<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Actions\Ai\EmbedLessonAnswers;
use App\Enums\AnswerPath;
use App\Enums\AnswerSource;
use App\Enums\ConsultantOutcome;
use App\Models\ConsultantQuestion;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Support\Ai\Embedder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeAnthropicTransport;
use Tests\TestCase;

/**
 * Консультант отвечает прежде всего по таблицам уроков.
 *
 * Строка таблицы написана человеком и им же выверена, поэтому она всегда важнее
 * того, что удалось выхватить из текста поиском. Нарезка текста остаётся
 * запасным путём — для уроков, которые ещё не размечены.
 */
final class CuratedAnswersTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * Ради чего всё и затевалось: выверенный автором ответ выигрывает у абзаца,
     * случайно совпавшего с вопросом теми же словами.
     */
    public function test_a_curated_row_is_preferred_over_the_lesson_text(): void
    {
        $lesson = $this->publishedLesson(
            'Покраска стен',
            'Здесь много общих слов про краску и сушку, но точного срока в тексте нет.',
        );

        $this->rowOn($lesson, 'Сколько сохнет второй слой краски?', 'Не менее 4 часов при 20 °C.');

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Четыре часа [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Сколько сохнет второй слой краски?'])
            ->assertOk()
            ->assertJsonCount(1, 'data.sources')
            ->assertJsonPath('data.sources.0.question', 'Сколько сохнет второй слой краски?')
            ->assertJsonPath('data.sources.0.quote', 'Не менее 4 часов при 20 °C.');

        $sent = (string) $transport->payload()['messages'][0]['content'];

        $this->assertStringContainsString('Вопрос: Сколько сохнет второй слой краски?', $sent);
        $this->assertStringNotContainsString('точного срока в тексте нет', $sent);

        $this->assertSame(AnswerPath::Curated, ConsultantQuestion::query()->sole()->answered_from);
    }

    /**
     * Урок без таблицы не выпадает из базы знаний: по нему по-прежнему ищется
     * текст. Иначе переделка стоила бы авторам всей накопленной базы разом.
     */
    public function test_an_unmarked_lesson_still_answers_from_its_text(): void
    {
        $this->publishedLesson('Работа с возражениями', 'Когда клиент говорит «дорого», выслушайте его.');

        $this->fakeModel(FakeAnthropicTransport::replying('Выслушайте [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Что делать, если клиент говорит дорого?'])
            ->assertOk()
            ->assertJsonCount(1, 'data.sources')
            ->assertJsonPath('data.sources.0.question', null);

        $this->assertSame(AnswerPath::Passages, ConsultantQuestion::query()->sole()->answered_from);
    }

    /** Черновик не виден консультанту и через таблицу тоже. */
    public function test_a_row_from_an_unpublished_course_is_never_found(): void
    {
        $draft = $this->lessonIn(Course::factory()->create(['title' => 'Черновик']), 'Секрет', 'Текст черновика.');
        $this->rowOn($draft, 'Сколько сохнет второй слой краски?', 'Секретные четыре часа.');

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Не должно быть вызвано.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Сколько сохнет второй слой краски?'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'В базе знаний об этом ничего нет.');

        $this->assertFalse($transport->wasCalled());
    }

    /**
     * Место, а не только урок. Ради этого таблица и заводилась: по часовой
     * записи «проверьте сами» — это поиск глазами.
     */
    public function test_a_citation_carries_the_exact_place_in_the_lesson(): void
    {
        $lesson = $this->publishedLesson('Покраска стен', 'Текст урока.');
        $lesson->forceFill(['video_url' => 'https://youtu.be/xxxxxxxxxxx'])->save();

        $this->rowOn(
            $lesson,
            'Сколько сохнет второй слой краски?',
            'Не менее 4 часов.',
            ['source_kind' => AnswerSource::Video, 'source_seconds' => 755],
        );

        $this->fakeModel(FakeAnthropicTransport::replying('Четыре часа [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Сколько сохнет второй слой краски?'])
            ->assertOk()
            ->assertJsonPath('data.sources.0.location.kind', 'video')
            ->assertJsonPath('data.sources.0.location.seconds', 755)
            ->assertJsonPath('data.sources.0.location.label', 'Видео урока, 12:35');
    }

    /* ---------- дословный ответ ---------- */

    /**
     * Вопрос совпал со строкой точно — модель не вызывается вовсе.
     *
     * Это и есть выигрыш от курирования: мгновенно, бесплатно и переврать
     * выверенную формулировку нечему.
     */
    public function test_an_exact_match_is_answered_without_calling_the_model(): void
    {
        $this->withEmbeddings();

        $lesson = $this->publishedLesson('Покраска стен', 'Текст урока.');
        $this->rowOn($lesson, 'Сколько сохнет второй слой краски?', 'Не менее 4 часов при 20 °C.');
        $this->indexRows();

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Не должно быть вызвано.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Сколько сохнет второй слой краски?'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'Не менее 4 часов при 20 °C.')
            ->assertJsonPath('data.verbatim', true)
            ->assertJsonCount(1, 'data.sources');

        $this->assertFalse($transport->wasCalled(), 'Модель вызвали, хотя ответ был готов.');

        $journal = ConsultantQuestion::query()->sole();

        $this->assertSame(ConsultantOutcome::Verbatim, $journal->outcome);
        $this->assertSame(AnswerPath::Curated, $journal->answered_from);
    }

    /**
     * Две одинаково близкие строки — случай, где выбирать наугад нельзя: это
     * ровно тот вопрос, на который в базе два разных ответа. Решает модель,
     * получив обе.
     */
    public function test_two_equally_close_rows_go_to_the_model_instead(): void
    {
        $this->withEmbeddings();

        $lesson = $this->publishedLesson('Покраска стен', 'Текст урока.');
        $this->rowOn($lesson, 'Сколько сохнет краска в тепле?', 'Четыре часа при 20 °C.');
        $this->rowOn($lesson, 'Сколько сохнет краска на морозе?', 'Двенадцать часов при 5 °C.');
        $this->indexRows();

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Смотря где [источник 1][источник 2].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Сколько сохнет краска?'])
            ->assertOk()
            ->assertJsonPath('data.verbatim', false)
            ->assertJsonCount(2, 'data.sources');

        $this->assertTrue($transport->wasCalled(), 'Один из двух ответов отдали, не спросив модель.');
        $this->assertSame(ConsultantOutcome::Answered, ConsultantQuestion::query()->sole()->outcome);
    }

    /**
     * В вектор строки идут её собственные слова и ничего больше.
     *
     * Сравнивают его с вопросом сотрудника, а тот не несёт ни названия курса,
     * ни названия урока. Допиши их к строке — и сравнение пойдёт между
     * неравным, причём тем хуже, чем строка короче: у строки в два слова
     * заголовки перевешивали её саму, вектор получался про курс, и вопрос,
     * повторяющий строку слово в слово, до неё не доходил.
     */
    public function test_a_row_is_embedded_by_its_own_words_and_nothing_else(): void
    {
        $this->withEmbeddings();

        $lesson = $this->lessonIn(
            Course::factory()->published()->create(['title' => 'Оформление реализации в 1С']),
            'Номера сотрудников',
            'Текст урока.',
        );

        $this->rowOn($lesson, 'Мямаев Хасбулла', 'Завхоз бисмара');
        $this->indexRows();

        $sent = [];

        Http::assertSent(function (Request $request) use (&$sent): bool {
            if (str_contains($request->url(), '/v1/embeddings')) {
                $sent = [...$sent, ...$request->data()['input']];
            }

            return true;
        });

        $this->assertContains('Мямаев Хасбулла', $sent, 'Вопрос строки не ушёл в вектор как есть.');
        $this->assertContains('Завхоз бисмара', $sent, 'Ответ строки не ушёл в вектор как есть.');

        // Рядом считаются и векторы фрагментов урока — те заголовки сохраняют
        // намеренно. Проверяется поэтому не весь список, а то, что текст
        // строки нигде не ушёл склеенным с чем-то ещё.
        foreach ($sent as $text) {
            if (str_contains($text, 'Мямаев Хасбулла')) {
                $this->assertSame('Мямаев Хасбулла', $text, 'В вектор строки попали заголовки.');
            }
        }
    }

    /* ---------- helpers ---------- */

    /**
     * Включает смысловой поиск на управляемых векторах.
     *
     * Вектор задаётся словом, которое встретилось в тексте: всё про краску
     * смотрит в одну сторону, всё прочее — в другую. Так близость становится
     * ровно 1 или 0, и порог с отрывом проверяются без подгонки чисел.
     */
    private function withEmbeddings(): void
    {
        config(['ai.api_key' => 'test-key', 'ai.embedding_model' => 'test-embeddings']);

        Http::fake(['*/v1/embeddings' => function (Request $request): array {
            /** @var list<string> $inputs */
            $inputs = $request->data()['input'];

            return ['data' => array_map(static function (string $text): array {
                $paint = mb_stripos($text, 'краск') !== false || mb_stripos($text, 'сохнет') !== false;

                return ['embedding' => array_pad([$paint ? 1.0 : 0.0, $paint ? 0.0 : 1.0], Embedder::DIMENSIONS, 0.0)];
            }, $inputs)];
        }]);
    }

    private function indexRows(): void
    {
        app(EmbedLessonAnswers::class)->handle();
    }

    private function fakeModel(FakeAnthropicTransport $transport): FakeAnthropicTransport
    {
        $this->app->instance(Client::class, new Client(
            apiKey: 'test-key',
            requestOptions: RequestOptions::with(transporter: $transport, maxRetries: 0),
        ));

        return $transport;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function rowOn(Lesson $lesson, string $question, string $answer, array $source = []): void
    {
        $lesson->answers()->create([
            'position' => $lesson->answers()->count(),
            'question' => $question,
            'answer' => $answer,
            'source_kind' => AnswerSource::Text,
            ...$source,
        ]);
    }

    private function publishedLesson(string $title, string $content): Lesson
    {
        return $this->lessonIn(Course::factory()->published()->create(), $title, $content);
    }

    private function lessonIn(Course $course, string $title, string $content): Lesson
    {
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        return Lesson::factory()->create([
            'module_id' => $module->id,
            'title' => $title,
            'content' => $content,
        ]);
    }
}
