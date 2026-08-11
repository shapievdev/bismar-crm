<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Actions\Lms\SaveLessonTranscript;
use App\Enums\AnswerSource;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonAnswer;
use App\Models\LessonAttachment;
use App\Support\Lms\BlockIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeAnthropicTransport;
use Tests\TestCase;

/**
 * Черновик таблицы, предложенный моделью по расшифровкам.
 *
 * Два свойства здесь главные. Он ничего не сохраняет — «в поиск попадает только
 * утверждённое» выполняется тем, что неутверждённой строки не существует. И у
 * каждого предложения источник уже проставлен: он выведен из расшифровки, а не
 * угадан, — автору не остаётся ничего указывать руками.
 */
final class SuggestAnswersTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_a_draft_is_returned_and_saved_nowhere(): void
    {
        $lesson = $this->lessonWithArticle();

        $this->fakeModel($this->replyWith([
            ['question' => 'Сколько сохнет второй слой?', 'answer' => 'Не менее 4 часов.', 'fragment' => 1],
        ]));

        $this->actingAs($this->author())
            ->postJson(route('lms.answers.suggest', $lesson))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question', 'Сколько сохнет второй слой?')
            ->assertJsonPath('data.0.source_kind', AnswerSource::Text->value);

        $this->assertSame(0, LessonAnswer::query()->count(), 'Черновик попал в базу, минуя автора.');
    }

    /**
     * То, ради чего расшифровки привязаны к единицам содержания: вопрос из
     * записи получает и вид источника, и секунду — без единого действия автора.
     * Раньше модель не видела видео вовсе и место указать не могла.
     */
    public function test_a_question_from_the_video_arrives_with_its_timecode(): void
    {
        $lesson = $this->lessonWithArticle();
        $lesson->forceFill(['video_url' => 'https://youtu.be/xxxxxxxxxxx'])->save();

        $this->transcribe($lesson, AnswerSource::Video, <<<'SRT'
        1
        00:12:35,000 --> 00:12:39,500
        Второй слой сохнет не менее четырёх часов при двадцати градусах.
        SRT);

        // Фрагмент 1 — расшифровка статьи, 2 — расшифровка записи: они уходят
        // модели в порядке расшифровок.
        $this->fakeModel($this->replyWith([
            ['question' => 'Сколько сохнет второй слой?', 'answer' => 'Не менее 4 часов.', 'fragment' => 2],
        ]));

        $this->actingAs($this->author())
            ->postJson(route('lms.answers.suggest', $lesson))
            ->assertOk()
            ->assertJsonPath('data.0.source_kind', AnswerSource::Video->value)
            ->assertJsonPath('data.0.source_seconds', 755)
            ->assertJsonPath('data.0.source_block_id', null);
    }

    public function test_a_question_from_a_file_arrives_with_its_attachment(): void
    {
        $lesson = $this->lessonWithArticle();
        $attachment = $this->attachmentOn($lesson);

        $this->transcribe(
            $lesson,
            AnswerSource::Attachment,
            "--- Страница 4 ---\n\nВлажные помещения требуют краски с маркировкой «для ванных».",
            $attachment->id,
        );

        $this->fakeModel($this->replyWith([
            ['question' => 'Какую краску брать в ванную?', 'answer' => 'С маркировкой «для ванных».', 'fragment' => 2],
        ]));

        $this->actingAs($this->author())
            ->postJson(route('lms.answers.suggest', $lesson))
            ->assertOk()
            ->assertJsonPath('data.0.source_kind', AnswerSource::Attachment->value)
            ->assertJsonPath('data.0.source_attachment_id', $attachment->id)
            ->assertJsonPath('data.0.source_page', 4);
    }

    /**
     * Автор просит вопросы у той расшифровки, которую только что вставил, —
     * разбирать урок целиком ему в этот момент незачем.
     */
    public function test_only_the_named_transcript_is_read(): void
    {
        $lesson = $this->lessonWithArticle();
        $lesson->forceFill(['video_url' => 'https://youtu.be/xxxxxxxxxxx'])->save();

        $video = app(SaveLessonTranscript::class)->handle(
            lesson: $lesson,
            kind: AnswerSource::Video,
            raw: '00:12:35 Второй слой сохнет не менее четырёх часов.',
        );

        $transport = $this->fakeModel($this->replyWith([
            ['question' => 'Сколько сохнет?', 'answer' => 'Четыре часа.', 'fragment' => 1],
        ]));

        $this->actingAs($this->author())
            ->postJson(route('lms.answers.suggest', $lesson).'?transcript='.$video->id)
            ->assertOk()
            // Первым фрагментом теперь запись, а не статья: больше ничего не
            // передавали.
            ->assertJsonPath('data.0.source_kind', AnswerSource::Video->value)
            ->assertJsonPath('data.0.source_seconds', 755);

        $sent = (string) $transport->payload()['messages'][0]['content'];

        $this->assertStringNotContainsString('Второй слой сохнет 4 часа.', $sent);
    }

    /** Чужую расшифровку сюда не передать: выбирается она среди своих. */
    public function test_a_transcript_from_another_lesson_is_ignored(): void
    {
        $lesson = $this->lessonWithArticle();

        $stranger = app(SaveLessonTranscript::class)->handle(
            lesson: $this->lessonWithArticle(),
            kind: AnswerSource::Text,
            raw: 'Совершенно посторонний текст.',
        );

        $transport = $this->fakeModel($this->replyWith([
            ['question' => 'Вопрос?', 'answer' => 'Ответ.', 'fragment' => 1],
        ]));

        $this->actingAs($this->author())
            ->postJson(route('lms.answers.suggest', $lesson).'?transcript='.$stranger->id)
            ->assertOk();

        $sent = (string) $transport->payload()['messages'][0]['content'];

        $this->assertStringNotContainsString('Совершенно посторонний текст.', $sent);
    }

    /**
     * Модель нередко оборачивает JSON в разметку или предваряет фразой. Ответ
     * от этого не становится негодным.
     */
    public function test_a_draft_wrapped_in_markup_is_still_read(): void
    {
        $lesson = $this->lessonWithArticle();

        $this->fakeModel(FakeAnthropicTransport::replying(
            "Вот что я нашёл:\n```json\n[{\"question\": \"Вопрос?\", \"answer\": \"Ответ.\", \"fragment\": 1}]\n```",
        ));

        $this->actingAs($this->author())
            ->postJson(route('lms.answers.suggest', $lesson))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * Выдуманный номер фрагмента означал бы строку с источником, которого
     * модели не показывали.
     */
    public function test_an_invented_fragment_number_is_dropped(): void
    {
        $lesson = $this->lessonWithArticle();

        $this->fakeModel($this->replyWith([
            ['question' => 'Вопрос?', 'answer' => 'Ответ.', 'fragment' => 99],
        ]));

        $this->actingAs($this->author())
            ->postJson(route('lms.answers.suggest', $lesson))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_row_without_question_or_answer_is_discarded(): void
    {
        $lesson = $this->lessonWithArticle();

        $this->fakeModel($this->replyWith([
            ['question' => 'Годный вопрос?', 'answer' => 'Ответ.', 'fragment' => 1],
            ['question' => '', 'answer' => 'Ответ без вопроса.', 'fragment' => 1],
            ['question' => 'Вопрос без ответа?', 'answer' => '', 'fragment' => 1],
        ]));

        $this->actingAs($this->author())
            ->postJson(route('lms.answers.suggest', $lesson))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.question', 'Годный вопрос?');
    }

    /** Урок без единой расшифровки модели не показывают вовсе. */
    public function test_a_lesson_with_nothing_transcribed_never_reaches_the_model(): void
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $bare = Lesson::factory()->create(['module_id' => $module->id, 'content' => '']);

        $transport = $this->fakeModel($this->replyWith([]));

        $this->actingAs($this->author())
            ->postJson(route('lms.answers.suggest', $bare))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertFalse($transport->wasCalled());
    }

    /**
     * Разметить таблицу руками можно и без подсказки, поэтому отказ модели
     * сообщается, но работать не мешает.
     */
    public function test_a_model_failure_is_reported_without_a_provider_message(): void
    {
        $this->fakeModel(FakeAnthropicTransport::unreachable());

        $this->actingAs($this->author())
            ->postJson(route('lms.answers.suggest', $this->lessonWithArticle()))
            ->assertServiceUnavailable()
            ->assertJsonPath(
                'message',
                'Подсказка сейчас недоступна. Заполните таблицу вручную или попробуйте позже.',
            );
    }

    public function test_a_reader_may_not_ask_for_a_draft(): void
    {
        $this->actingAs($this->learner())
            ->postJson(route('lms.answers.suggest', $this->lessonWithArticle()))
            ->assertForbidden();
    }

    /* ---------- helpers ---------- */

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replyWith(array $rows): FakeAnthropicTransport
    {
        return FakeAnthropicTransport::replying((string) json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    private function fakeModel(FakeAnthropicTransport $transport): FakeAnthropicTransport
    {
        $this->app->instance(Client::class, new Client(
            apiKey: 'test-key',
            requestOptions: RequestOptions::with(transporter: $transport, maxRetries: 0),
        ));

        return $transport;
    }

    private function transcribe(Lesson $lesson, AnswerSource $kind, string $raw, ?int $attachmentId = null): void
    {
        app(SaveLessonTranscript::class)->handle(
            lesson: $lesson,
            kind: $kind,
            raw: $raw,
            attachmentId: $attachmentId,
        );
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

    private function lessonWithArticle(): Lesson
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        $document = (new BlockIdentifier)->assign([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Второй слой сохнет 4 часа.']]],
            ],
        ]);

        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'title' => 'Покраска стен',
            'content' => 'Второй слой сохнет 4 часа.',
        ]);

        $lesson->forceFill(['content_json' => $document])->save();

        return $lesson->refresh();
    }
}
