<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\AnswerSource;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Support\Ai\Embedder;
use App\Support\Lms\BlockIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Таблица урока: какие вопросы он разбирает и где ответ на каждый.
 *
 * Половина проверок здесь — про источник, указывающий в никуда. Такая ошибка
 * молчаливая: узнают о ней не при сохранении, а через месяц, когда сотрудник
 * нажмёт на источник в ответе консультанта и никуда не попадёт.
 */
final class LessonAnswersTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_an_author_saves_the_whole_table_at_once(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [
                [
                    'question' => 'Сколько сохнет второй слой?',
                    'answer' => 'Не менее 4 часов при 20 °C.',
                    'source_kind' => AnswerSource::Text->value,
                ],
                [
                    'question' => 'Чем красить углы?',
                    'answer' => 'Кистью.',
                    'source_kind' => AnswerSource::Text->value,
                ],
            ]])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.question', 'Сколько сохнет второй слой?')
            ->assertJsonPath('data.0.position', 0)
            ->assertJsonPath('data.1.position', 1);

        $this->assertSame(2, $lesson->answers()->count());
    }

    /**
     * Присланная таблица — вся таблица: строки, которых в ней нет, уходят.
     */
    public function test_rows_left_out_of_the_payload_are_removed(): void
    {
        $lesson = $this->lesson();
        $this->rowsOn($lesson, 'Первый', 'Второй', 'Третий');

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [[
                'question' => 'Первый',
                'answer' => 'Ответ.',
                'source_kind' => AnswerSource::Text->value,
            ]]])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame(1, $lesson->answers()->count());
    }

    /**
     * Строку правят из-за опечатки в номере страницы куда чаще, чем из-за
     * текста. Сбрасывать векторы при каждом сохранении значило бы гонять сервис
     * эмбеддингов впустую и оставлять строку ненаходимой, пока очередь не
     * дойдёт.
     */
    public function test_untouched_text_keeps_its_vectors(): void
    {
        $lesson = $this->lessonWithVideo();

        $row = $lesson->answers()->create([
            'position' => 0,
            'question' => 'Сколько сохнет?',
            'answer' => 'Четыре часа.',
            'source_kind' => AnswerSource::Text,
        ]);

        $row->forceFill([
            'question_embedding' => 'вектор-вопроса',
            'answer_embedding' => 'вектор-ответа',
            'embedding_model' => 'какая-то-модель',
        ])->save();

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [[
                'question' => 'Сколько сохнет?',
                'answer' => 'Четыре часа.',
                'source_kind' => AnswerSource::Video->value,
                'source_seconds' => 755,
            ]]])
            ->assertOk();

        $this->assertSame('вектор-вопроса', $row->refresh()->question_embedding);
        $this->assertSame(755, $row->source_seconds);
    }

    public function test_changed_text_drops_its_vectors(): void
    {
        $lesson = $this->lesson();

        $row = $lesson->answers()->create([
            'position' => 0,
            'question' => 'Сколько сохнет?',
            'answer' => 'Четыре часа.',
            'source_kind' => AnswerSource::Text,
        ]);

        $row->forceFill(['question_embedding' => 'вектор', 'answer_embedding' => 'вектор'])->save();

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [[
                'question' => 'Сколько сохнет второй слой?',
                'answer' => 'Четыре часа.',
                'source_kind' => AnswerSource::Text->value,
            ]]])
            ->assertOk();

        $this->assertNull($row->refresh()->question_embedding);
    }

    /**
     * Поля места, не относящиеся к выбранному виду, пережить смену вида не
     * должны: строка с таймкодом и номером страницы разом не значит ничего.
     */
    public function test_switching_the_source_kind_clears_the_old_place(): void
    {
        $lesson = $this->lessonWithVideo();

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [[
                'question' => 'Вопрос?',
                'answer' => 'Ответ.',
                'source_kind' => AnswerSource::Video->value,
                'source_seconds' => 755,
            ]]])
            ->assertOk();

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [[
                'question' => 'Вопрос?',
                'answer' => 'Ответ.',
                'source_kind' => AnswerSource::Text->value,
                // Прислано вместе со сменой вида — и записано быть не должно.
                'source_seconds' => 755,
            ]]])
            ->assertOk()
            ->assertJsonPath('data.0.source_seconds', null);
    }

    /**
     * Ссылка на запись без секунды — это ссылка на запись с начала, и она
     * осмысленна. Вопрос, предложенный по расшифровке без таймкодов, приходит
     * именно таким: запрет на него отключал сохранение всей таблицы.
     */
    public function test_a_video_source_without_a_timecode_is_allowed(): void
    {
        $lesson = $this->lessonWithVideo();

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [[
                'question' => 'О чём эта запись?',
                'answer' => 'О покраске стен.',
                'source_kind' => AnswerSource::Video->value,
                'source_seconds' => null,
            ]]])
            ->assertOk()
            ->assertJsonPath('data.0.source_seconds', null)
            ->assertJsonPath('data.0.source_is_live', true);
    }

    /**
     * Сохранил на сайте — векторы посчитаны, и строку уже находит смысловой
     * поиск. Без работника очереди, о котором надо помнить: пока его забывали
     * запускать, ни один урок векторов не получал вовсе.
     */
    public function test_saving_from_the_site_computes_the_vectors_right_away(): void
    {
        $this->withEmbeddings();

        $lesson = $this->lesson();

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [[
                'question' => 'Сколько сохнет второй слой?',
                'answer' => 'Не менее 4 часов при 20 °C.',
                'source_kind' => AnswerSource::Text->value,
            ]]])
            ->assertOk()
            ->assertJsonPath('data.0.is_indexed', true);

        $row = $lesson->answers()->sole();

        $this->assertNotNull($row->question_embedding);
        $this->assertNotNull($row->answer_embedding);
    }

    /**
     * Сервис эмбеддингов посторонний, а текст — авторский: его отказ не смеет
     * отменить сохранение.
     */
    public function test_a_failing_embedder_does_not_lose_the_authors_work(): void
    {
        $this->withEmbeddings(failing: true);

        $lesson = $this->lesson();

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [[
                'question' => 'Сколько сохнет второй слой?',
                'answer' => 'Не менее 4 часов при 20 °C.',
                'source_kind' => AnswerSource::Text->value,
            ]]])
            ->assertOk();

        $row = $lesson->answers()->sole();

        $this->assertSame('Сколько сохнет второй слой?', $row->question);
        $this->assertNull($row->question_embedding, 'Вектор посчитан, хотя сервис отказал.');
    }

    /* ---------- источник, указывающий в никуда ---------- */

    public function test_an_attachment_from_another_lesson_is_refused(): void
    {
        $lesson = $this->lesson();
        $stranger = $this->attachmentOn($this->lesson());

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [[
                'question' => 'Вопрос?',
                'answer' => 'Ответ.',
                'source_kind' => AnswerSource::Attachment->value,
                'source_attachment_id' => $stranger->id,
            ]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('answers.0.source_attachment_id');
    }

    public function test_a_timecode_on_a_lesson_without_video_is_refused(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [[
                'question' => 'Вопрос?',
                'answer' => 'Ответ.',
                'source_kind' => AnswerSource::Video->value,
                'source_seconds' => 755,
            ]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('answers.0.source_kind');
    }

    public function test_a_block_that_is_no_longer_in_the_text_is_refused(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [[
                'question' => 'Вопрос?',
                'answer' => 'Ответ.',
                'source_kind' => AnswerSource::Text->value,
                'source_block_id' => 'блок-которого-нет',
            ]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('answers.0.source_block_id');
    }

    /**
     * Короткой статье указывать абзац нечего — ссылка на текст урока целиком
     * тоже осмысленна.
     */
    public function test_a_text_source_without_a_block_is_allowed(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [[
                'question' => 'Вопрос?',
                'answer' => 'Ответ.',
                'source_kind' => AnswerSource::Text->value,
                'source_block_id' => null,
            ]]])
            ->assertOk();
    }

    public function test_a_block_that_exists_is_accepted(): void
    {
        $lesson = $this->lessonWithArticle();
        $block = (new BlockIdentifier)->identifiers($lesson->content_json)[0];

        $this->actingAs($this->author())
            ->putJson(route('lms.answers.save', $lesson), ['answers' => [[
                'question' => 'Вопрос?',
                'answer' => 'Ответ.',
                'source_kind' => AnswerSource::Text->value,
                'source_block_id' => $block,
            ]]])
            ->assertOk()
            ->assertJsonPath('data.0.source_block_id', $block)
            ->assertJsonPath('data.0.source_is_live', true);
    }

    /**
     * Файл удалили — ответ верным быть не перестал, потерялась ссылка на место.
     * Строка обязана это пережить и сказать автору, что источник надо
     * переуказать.
     */
    public function test_a_row_outlives_the_file_it_pointed_at(): void
    {
        $lesson = $this->lesson();
        $attachment = $this->attachmentOn($lesson);

        $row = $lesson->answers()->create([
            'position' => 0,
            'question' => 'Вопрос?',
            'answer' => 'Ответ.',
            'source_kind' => AnswerSource::Attachment,
            'source_attachment_id' => $attachment->id,
        ]);

        $attachment->delete();

        $this->assertTrue($row->refresh()->exists);
        $this->assertNull($row->source_attachment_id);
        $this->assertFalse($row->hasLiveSource());
    }

    /* ---------- права ---------- */

    public function test_a_reader_may_not_edit_the_table(): void
    {
        $this->actingAs($this->learner())
            ->putJson(route('lms.answers.save', $this->lesson()), ['answers' => []])
            ->assertForbidden();
    }

    public function test_the_table_is_returned_with_the_lesson(): void
    {
        $lesson = $this->lesson();
        $this->rowsOn($lesson, 'Сколько сохнет?');

        $this->actingAs($this->learner())
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonCount(1, 'data.answers')
            ->assertJsonPath('data.answers.0.question', 'Сколько сохнет?');
    }

    /**
     * Каждая строка проверяет, цело ли место, на которое она указывает, — и для
     * текста это значит заглянуть в статью. Без обратной ссылки на урок она
     * лезла бы за ним отдельным запросом, за тем самым, который её и загрузил:
     * десять строк — десять лишних запросов на каждый показ урока.
     */
    public function test_showing_a_lesson_does_not_query_once_per_row(): void
    {
        // Именно со ссылкой на абзац: у строки, указывающей на текст урока
        // целиком, проверять нечего, и до статьи она не добирается.
        $lesson = $this->lessonWithArticle();
        $block = (new BlockIdentifier)->identifiers($lesson->content_json)[0];

        $this->rowsAtBlock($lesson, $block, 5);

        $learner = $this->learner();

        // Первый показ заводит запись на курс — база знаний не требует
        // подписки, — и потому стоит нескольких запросов сверх обычного.
        // Замерять надо установившееся поведение.
        $this->actingAs($learner)->getJson(route('lms.lessons.show', $lesson))->assertOk();

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->actingAs($learner)->getJson(route('lms.lessons.show', $lesson))->assertOk();

        $withFive = $count;

        $this->rowsAtBlock($lesson->refresh(), $block, 5);

        $count = 0;
        $this->actingAs($learner)->getJson(route('lms.lessons.show', $lesson))->assertOk();

        // Число запросов не должно зависеть от числа строк. Сравниваем два
        // показа, а не проверяем точное число: запросы вокруг (сессия, права,
        // запись на курс) к таблице отношения не имеют и меняются сами по себе.
        $this->assertSame($withFive, $count, 'Показ урока делает запрос на каждую строку таблицы.');
    }

    /* ---------- helpers ---------- */

    /**
     * Включает смысловой поиск на управляемых векторах.
     *
     * `failing` изображает недоступный сервис — тот случай, когда сохранение
     * обязано состояться всё равно.
     */
    private function withEmbeddings(bool $failing = false): void
    {
        config(['ai.api_key' => 'test-key', 'ai.embedding_model' => 'test-embeddings']);

        Http::fake(['*/v1/embeddings' => function (Request $request) use ($failing) {
            if ($failing) {
                return Http::response('сервис недоступен', 503);
            }

            /** @var list<string> $inputs */
            $inputs = $request->data()['input'];

            return Http::response(['data' => array_map(
                static fn (): array => ['embedding' => array_pad([1.0], Embedder::DIMENSIONS, 0.0)],
                $inputs,
            )]);
        }]);
    }

    private function lesson(): Lesson
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        return Lesson::factory()->create(['module_id' => $module->id]);
    }

    private function lessonWithVideo(): Lesson
    {
        $lesson = $this->lesson();
        $lesson->forceFill(['video_url' => 'https://youtu.be/xxxxxxxxxxx'])->save();

        return $lesson;
    }

    /** Урок со статьёй, чьи блоки уже получили имена. */
    private function lessonWithArticle(): Lesson
    {
        $lesson = $this->lesson();

        $document = (new BlockIdentifier)->assign([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Второй слой сохнет 4 часа.']]],
            ],
        ]);

        $lesson->forceFill(['content_json' => $document])->save();

        return $lesson->refresh();
    }

    private function attachmentOn(Lesson $lesson): LessonAttachment
    {
        return LessonAttachment::query()->create([
            'lesson_id' => $lesson->id,
            'disk' => 's3',
            'path' => 'lessons/'.$lesson->id.'/matrix.pdf',
            'name' => 'Матрица.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);
    }

    /** Строки, ссылающиеся на конкретный абзац: только они смотрят в статью. */
    private function rowsAtBlock(Lesson $lesson, string $block, int $count): void
    {
        $next = $lesson->answers()->count();

        for ($offset = 0; $offset < $count; $offset++) {
            $lesson->answers()->create([
                'position' => $next + $offset,
                'question' => 'Вопрос '.($next + $offset),
                'answer' => 'Ответ.',
                'source_kind' => AnswerSource::Text,
                'source_block_id' => $block,
            ]);
        }
    }

    private function rowsOn(Lesson $lesson, string ...$questions): void
    {
        $next = $lesson->answers()->count();

        foreach ($questions as $offset => $question) {
            $lesson->answers()->create([
                'position' => $next + $offset,
                'question' => $question,
                'answer' => 'Ответ.',
                'source_kind' => AnswerSource::Text,
            ]);
        }
    }
}
