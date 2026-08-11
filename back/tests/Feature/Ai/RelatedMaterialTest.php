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
 * Консультант не отделывается словами «ничего нет», когда база знает соседнее.
 *
 * Прежде найденное делилось надвое молча: что прошло порог — уходило в ответ,
 * что не прошло — исчезало. Сотрудник, спросивший о разобранном чуть иначе,
 * получал отказ, хотя разбор смежного случая лежал рядом. Теперь у консультанта
 * три исхода: ответить, показать близкое, и — только если нет и близкого —
 * сказать, что материала нет.
 */
final class RelatedMaterialTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * То, что не поместилось в ответ, читателю всё же называют.
     *
     * Бюджет фрагментов не резиновый, и второй урок по той же теме в него не
     * влезает. Прежде он на этом и терялся — теперь становится карточкой
     * «смотрите также», не занимая места в промпте и не сбивая модель с
     * лучшего материала.
     */
    public function test_material_that_did_not_fit_the_answer_is_offered_alongside_it(): void
    {
        // Один фрагмент на ответ: столько же, сколько бывает на живой базе,
        // где кандидатов десятки, а бюджет — восемь.
        config(['ai.lessons_per_answer' => 1]);

        $course = Course::factory()->published()->create();

        $this->lessonIn($course, 'Работа с возражениями', 'Когда клиент говорит «дорого», выслушайте и уточните.');
        $this->lessonIn($course, 'Возражения по срокам', 'Возражение о сроках снимают графиком поставки.');

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Выслушайте и уточните [источник 1].'));

        $response = $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Что отвечать на возражения клиента?'])
            ->assertOk()
            ->assertJsonCount(1, 'data.sources')
            ->assertJsonCount(1, 'data.related');

        $answered = $response->json('data.sources.0.lesson_title');
        $offered = $response->json('data.related.0.lesson_title');

        $this->assertNotSame($answered, $offered, 'Рядом с ответом предложен тот же урок, что и в ответе.');

        // Близкое показывают читателю, но не подмешивают модели: получив рядом
        // с верным фрагментом соседний, слабая модель отвечает по тому, что ей
        // приглянулось.
        $sent = (string) $transport->payload()['messages'][0]['content'];

        $this->assertStringNotContainsString((string) $offered, $sent);
    }

    /**
     * Урок, у которого совпало одно название, — тоже ответ на «ничего нет».
     *
     * Обычный поиск идёт по расшифровкам и такой урок не видит вовсе: слово
     * вопроса живёт в названии курса и не встречается ни в одной из них. Досюда
     * доходят вопросы, по которым не нашлось ничего другого, и назвать урок
     * честнее, чем промолчать.
     */
    public function test_a_lesson_matching_only_by_its_course_name_is_offered_when_nothing_else_matched(): void
    {
        $this->lessonIn(
            Course::factory()->published()->create(['title' => 'Работа с претензиями и возвратами']),
            'Общие сведения',
            'Здесь пока пусто.',
        );

        $transport = $this->fakeModel(FakeAnthropicTransport::replying(
            'Прямого ответа на это в материалах нет. Есть курс про возвраты [источник 1].',
        ));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Как оформить возврат бракованного товара?'])
            ->assertOk()
            ->assertJsonCount(1, 'data.sources')
            ->assertJsonPath('data.sources.0.course_title', 'Работа с претензиями и возвратами')
            ->assertJsonPath('data.sources.0.lesson_title', 'Общие сведения');

        // Заголовок над фрагментами — то, чем правило «не выдавай соседнее за
        // ответ» держится: без него близкое неотличимо от найденного.
        $sent = (string) $transport->payload()['messages'][0]['content'];

        $this->assertStringContainsString('БЛИЗКОЕ ПО ТЕМЕ', $sent);
        $this->assertStringContainsString('Содержание урока не приведено', $sent);

        // Для журнала это не ответ, а пробел: тема в базе есть, разобранного
        // вопроса в ней нет.
        $logged = ConsultantQuestion::query()->sole();

        $this->assertSame(ConsultantOutcome::Suggested, $logged->outcome);
        $this->assertSame(AnswerPath::Related, $logged->answered_from);
    }

    /** Вопрос, показавший близкое, остаётся в перечне дыр базы знаний. */
    public function test_a_question_answered_with_related_material_stays_a_gap_for_the_author(): void
    {
        $this->lessonIn(
            Course::factory()->published()->create(['title' => 'Работа с претензиями и возвратами']),
            'Общие сведения',
            'Здесь пока пусто.',
        );

        $this->fakeModel(FakeAnthropicTransport::replying('Прямого ответа на это в материалах нет [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Как оформить возврат бракованного товара?'])
            ->assertOk();

        $this->actingAs($this->author())
            ->getJson(route('ai.questions.index', ['outcome' => ConsultantOutcome::Suggested->value]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.answered_from_label', 'Близкий материал');
    }

    /**
     * Строка таблицы, не дотянувшая до ответа, тоже становится советом.
     *
     * Порог отбора отвечает на вопрос «можно ли этим отвечать», и строка про
     * соседний случай его не проходит. Прежде она на этом и заканчивалась —
     * теперь её показывают рядом с ответом: отвечать по ней нельзя, а знать о
     * ней сотруднику полезнее, чем не знать.
     */
    public function test_a_curated_row_below_the_answering_threshold_is_offered_beside_the_answer(): void
    {
        $this->withGradedEmbeddings();

        $course = Course::factory()->published()->create();

        $this->lessonIn($course, 'Покраска стен', 'Общие слова про покраску и разбавление.');
        $drying = $this->lessonIn($course, 'Сушка покрытий', 'Общие слова.');

        $this->rowOn($drying, 'Сколько сохнет второй слой краски?', 'Не менее 4 часов при 20 °C.');
        app(EmbedLessonAnswers::class)->handle();

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Общие слова [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Чем разбавляют краску перед покраской?'])
            ->assertOk()
            // Отвечает текст урока: строка таблицы до порога не дотянула.
            ->assertJsonPath('data.sources.0.quote', 'Общие слова про покраску и разбавление.')
            ->assertJsonCount(1, 'data.related')
            ->assertJsonPath('data.related.0.question', 'Сколько сохнет второй слой краски?')
            ->assertJsonPath('data.related.0.quote', 'Не менее 4 часов при 20 °C.');

        // Показана читателю, но модели не передана: отвечать ей нечем, а сбить
        // слабую модель с верного фрагмента она вполне способна.
        $sent = (string) $transport->payload()['messages'][0]['content'];

        $this->assertStringNotContainsString('Не менее 4 часов', $sent);
        $this->assertSame(AnswerPath::Passages, ConsultantQuestion::query()->sole()->answered_from);
    }

    /**
     * Нет ни ответа, ни близкого — модель по-прежнему не зовут.
     *
     * Смысл всей затеи: обосновать ответ нечем, и спрашивать модель именно в
     * этом положении значит получить правдоподобно выдуманный регламент.
     */
    public function test_a_question_with_nothing_close_still_never_reaches_the_model(): void
    {
        $this->publishedLesson('Работа с возражениями', 'Клиент говорит «дорого» — сначала выслушайте.');

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Не должно быть вызвано.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Как поменять картридж в принтере бухгалтерии'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'В материалах базы знаний об этом ничего нет.')
            ->assertJsonPath('data.related', []);

        $this->assertFalse($transport->wasCalled(), 'Модель спросили, хотя не нашлось даже близкого.');
    }

    /* ---------- helpers ---------- */

    /**
     * Включает смысловой поиск на управляемых векторах.
     *
     * Всё, что лежит в базе, смотрит в одну сторону; вопрос сотрудника — в
     * сторону, отклонённую ровно настолько, чтобы близость легла между широким
     * порогом и узким. Оба числа берутся из настроек, а не вписаны сюда:
     * проверяется поведение порогов, а не их сегодняшние значения.
     */
    private function withGradedEmbeddings(): void
    {
        config(['ai.api_key' => 'test-key', 'ai.embedding_model' => 'test-embeddings']);

        $between = ((float) config('ai.answers_floor') + (float) config('ai.answers_related_floor')) / 2;

        Http::fake(['*/v1/embeddings' => function (Request $request) use ($between): array {
            /** @var list<string> $inputs */
            $inputs = $request->data()['input'];

            return ['data' => array_map(static function (string $text) use ($between): array {
                $asked = str_contains($text, 'разбавляют');

                return ['embedding' => array_pad(
                    $asked ? [$between, sqrt(1 - $between ** 2)] : [1.0, 0.0],
                    Embedder::DIMENSIONS,
                    0.0,
                )];
            }, $inputs)];
        }]);
    }

    private function fakeModel(FakeAnthropicTransport $transport): FakeAnthropicTransport
    {
        $this->app->instance(Client::class, new Client(
            apiKey: 'test-key',
            requestOptions: RequestOptions::with(transporter: $transport, maxRetries: 0),
        ));

        return $transport;
    }

    private function rowOn(Lesson $lesson, string $question, string $answer): void
    {
        $lesson->answers()->create([
            'position' => $lesson->answers()->count(),
            'question' => $question,
            'answer' => $answer,
            'source_kind' => AnswerSource::Text,
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
