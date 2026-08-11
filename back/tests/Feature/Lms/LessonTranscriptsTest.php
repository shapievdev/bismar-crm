<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Enums\AnswerSource;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\LessonTranscript;
use App\Models\TranscriptSegment;
use App\Support\Lms\BlockIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeAnthropicTransport;
use Tests\TestCase;

/**
 * Расшифровки — то, чем содержание урока становится доступно консультанту.
 *
 * До них часовая запись и приложенный СНиП для базы знаний не существовали:
 * урок, где всё существенное сказано голосом, был пустым. Половина проверок
 * здесь именно об этом — что сказанное теперь находится, и находится с местом.
 */
final class LessonTranscriptsTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /* ---------- выведенные из статьи ---------- */

    /**
     * Набранный текст и так изложен словами: требовать от автора пересказывать
     * собственный абзац было бы издевательством.
     */
    public function test_the_article_gets_one_transcript_of_its_own_text(): void
    {
        $lesson = $this->lessonWithArticle();

        $transcript = $lesson->transcripts()->sole();

        $this->assertTrue($transcript->is_derived);
        $this->assertSame(AnswerSource::Text, $transcript->source_kind);
        $this->assertNull($transcript->source_block_id, 'Расшифровка текста заводится на урок, а не на абзац.');
        $this->assertStringContainsString('Второй слой сохнет', (string) $transcript->segments()->first()?->content);
    }

    /**
     * Одна на урок — но место внутри статьи не потеряно: его помнит кусок. На
     * статье в семьдесят абзацев прежнее устройство давало семьдесят
     * расшифровок, между которыми автору нечего выбирать.
     */
    public function test_a_long_article_yields_one_transcript_whose_pieces_know_their_blocks(): void
    {
        $lesson = $this->lessonWithArticle();

        $blocks = [];

        foreach (['Первый абзац про грунтовку.', 'Второй абзац про сушку.', 'Третий абзац про углы.'] as $text) {
            $blocks[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
        }

        $document = (new BlockIdentifier)->assign(['type' => 'doc', 'content' => $blocks]);
        $lesson->forceFill(['content_json' => $document, 'content' => 'Текст урока.'])->save();

        $this->assertSame(1, $lesson->transcripts()->count());

        $names = (new BlockIdentifier)->identifiers($document);
        $segments = $lesson->transcripts()->sole()->segments;

        $this->assertCount(3, $segments);
        $this->assertSame($names, $segments->pluck('source_block_id')->all());
    }

    public function test_a_derived_transcript_follows_the_text_it_came_from(): void
    {
        $lesson = $this->lessonWithArticle();

        $document = (new BlockIdentifier)->assign([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Углы красят кистью.']]],
            ],
        ]);

        $lesson->forceFill(['content_json' => $document, 'content' => 'Углы красят кистью.'])->save();

        $this->assertStringContainsString(
            'Углы красят кистью.',
            (string) $lesson->transcripts()->sole()->segments()->first()?->content,
        );
    }

    /**
     * Загруженная расшифровка нужна там, где слов в блоке мало, а смысла много
     * — под схемой или картинкой. Правку соседнего абзаца она обязана пережить.
     */
    public function test_an_uploaded_transcript_overrides_the_derived_one_and_survives_edits(): void
    {
        $lesson = $this->lessonWithArticle();

        $this->actingAs($this->author())
            ->postJson(route('lms.transcripts.store', $lesson), [
                'source_kind' => AnswerSource::Text->value,
                'text' => 'На схеме показан порядок нанесения слоёв.',
            ])
            ->assertCreated();

        // Правка урока пересобирает выведенные расшифровки — загруженная не
        // должна попасть под эту пересборку.
        $lesson->forceFill(['title' => 'Покраска стен и потолков'])->save();

        $transcript = $lesson->transcripts()->sole();

        $this->assertFalse($transcript->is_derived);
        $this->assertSame(
            'На схеме показан порядок нанесения слоёв.',
            $transcript->segments()->first()?->content,
        );
    }

    /**
     * Снимая расшифровку, автор не имел в виду сделать абзац невидимым для
     * поиска — на её место возвращается выведенная из собственного текста.
     */
    public function test_removing_an_uploaded_text_transcript_brings_the_derived_one_back(): void
    {
        $lesson = $this->lessonWithArticle();

        $this->actingAs($this->author())
            ->postJson(route('lms.transcripts.store', $lesson), [
                'source_kind' => AnswerSource::Text->value,
                'text' => 'На схеме показан порядок нанесения слоёв.',
            ])
            ->assertCreated();

        $this->actingAs($this->author())
            ->deleteJson(route('lms.transcripts.destroy', $lesson->transcripts()->sole()))
            ->assertNoContent();

        $restored = $lesson->transcripts()->sole();

        $this->assertTrue($restored->is_derived);
        $this->assertStringContainsString(
            'Второй слой сохнет',
            (string) $restored->segments()->first()?->content,
        );
    }

    /* ---------- загрузка ---------- */

    public function test_a_subtitle_file_becomes_timed_segments(): void
    {
        $lesson = $this->lessonWithVideo();

        $file = UploadedFile::fake()->createWithContent('lecture.srt', <<<'SRT'
        1
        00:12:35,000 --> 00:12:39,500
        Второй слой сохнет не менее четырёх часов.
        SRT);

        $this->actingAs($this->author())
            ->post(route('lms.transcripts.store', $lesson), [
                'source_kind' => AnswerSource::Video->value,
                'file' => $file,
            ])
            ->assertCreated()
            ->assertJsonPath('data.format', 'srt')
            ->assertJsonPath('data.original_name', 'lecture.srt');

        $segment = TranscriptSegment::query()
            ->whereRelation('transcript', 'source_kind', AnswerSource::Video->value)
            ->sole();

        $this->assertSame(755, $segment->starts_at_seconds);
    }

    /**
     * Куски — производное, и собрать из них обратно вставленное нельзя: разбор
     * их склеивает и выбрасывает разметку субтитров. Поэтому исходник хранится
     * рядом — иначе править расшифровку не из чего.
     */
    public function test_the_text_as_submitted_comes_back_for_editing(): void
    {
        $lesson = $this->lessonWithVideo();

        $raw = "1\n00:12:35,000 --> 00:12:39,500\nВторой слой сохнет не менее четырёх часов.";

        $this->actingAs($this->author())
            ->postJson(route('lms.transcripts.store', $lesson), [
                'source_kind' => AnswerSource::Video->value,
                'text' => $raw,
            ])
            ->assertCreated();

        $this->actingAs($this->author())
            ->getJson(route('lms.transcripts.index', $lesson))
            ->assertOk()
            ->assertJsonPath('data.1.content', $raw);
    }

    /** Правка выведенной расшифровки делает её загруженной — и она остаётся. */
    public function test_editing_a_derived_transcript_turns_it_into_an_uploaded_one(): void
    {
        $lesson = $this->lessonWithArticle();

        // Открыв её на правку, автор видит текст статьи, а не пустое поле.
        $this->assertStringContainsString(
            'Второй слой сохнет',
            (string) $lesson->transcripts()->sole()->content,
        );

        $this->actingAs($this->author())
            ->postJson(route('lms.transcripts.store', $lesson), [
                'source_kind' => AnswerSource::Text->value,
                'text' => 'Второй слой сохнет 4 часа. На схеме показан порядок нанесения.',
            ])
            ->assertCreated();

        $lesson->forceFill(['title' => 'Покраска стен и потолков'])->save();

        $this->assertFalse($lesson->transcripts()->sole()->is_derived);
    }

    public function test_a_second_upload_replaces_the_first(): void
    {
        $lesson = $this->lessonWithVideo();

        foreach (['Первая расшифровка.', 'Вторая расшифровка.'] as $text) {
            $this->actingAs($this->author())
                ->postJson(route('lms.transcripts.store', $lesson), [
                    'source_kind' => AnswerSource::Video->value,
                    'text' => $text,
                ])
                ->assertCreated();
        }

        $video = $lesson->transcripts()->where('source_kind', AnswerSource::Video->value)->sole();

        $this->assertSame('Вторая расшифровка.', $video->segments()->first()?->content);
    }

    /** Файл удалили — его расшифровка сама по себе ничего не значит. */
    public function test_a_transcript_goes_away_with_the_file_it_described(): void
    {
        $lesson = $this->lessonWithArticle();
        $attachment = $this->attachmentOn($lesson);

        $this->actingAs($this->author())
            ->postJson(route('lms.transcripts.store', $lesson), [
                'source_kind' => AnswerSource::Attachment->value,
                'source_attachment_id' => $attachment->id,
                'text' => 'Влажные помещения требуют краски с маркировкой «для ванных».',
            ])
            ->assertCreated();

        $attachment->delete();

        $this->assertSame(
            0,
            LessonTranscript::query()->where('source_kind', AnswerSource::Attachment->value)->count(),
        );
    }

    public function test_a_file_from_another_lesson_cannot_be_transcribed(): void
    {
        $stranger = $this->attachmentOn($this->lessonWithArticle());

        $this->actingAs($this->author())
            ->postJson(route('lms.transcripts.store', $this->lessonWithArticle()), [
                'source_kind' => AnswerSource::Attachment->value,
                'source_attachment_id' => $stranger->id,
                'text' => 'Текст.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_attachment_id');
    }

    public function test_an_empty_submission_is_refused(): void
    {
        $this->actingAs($this->author())
            ->postJson(route('lms.transcripts.store', $this->lessonWithVideo()), [
                'source_kind' => AnswerSource::Video->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('text');
    }

    /* ---------- скрытость ---------- */

    public function test_a_reader_can_neither_list_nor_upload_transcripts(): void
    {
        $lesson = $this->lessonWithArticle();

        $this->actingAs($this->learner())
            ->getJson(route('lms.transcripts.index', $lesson))
            ->assertForbidden();

        $this->actingAs($this->learner())
            ->postJson(route('lms.transcripts.store', $lesson), [
                'source_kind' => AnswerSource::Text->value,
                'text' => 'Текст.',
            ])
            ->assertForbidden();
    }

    /**
     * Расшифровка — не часть материала, а его изложение для машины. В уроке,
     * который читает сотрудник, её быть не должно ни в каком виде.
     */
    public function test_a_transcript_never_appears_in_the_lesson_a_reader_opens(): void
    {
        $lesson = $this->lessonWithVideo();

        $this->actingAs($this->author())
            ->postJson(route('lms.transcripts.store', $lesson), [
                'source_kind' => AnswerSource::Video->value,
                'text' => 'Совершенно секретная расшифровка записи.',
            ])
            ->assertCreated();

        $response = $this->actingAs($this->learner())
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk();

        $this->assertStringNotContainsString(
            'Совершенно секретная расшифровка записи.',
            (string) $response->getContent(),
        );
    }

    /**
     * Без модели эмбеддингов считать нечего, и ставить задание незачем.
     *
     * Иначе очередь копит их десятками, и со стороны это неотличимо от
     * медленной работы: заданий много, «векторы всё считаются», — хотя считать
     * не начинали и не начнут.
     */
    public function test_nothing_is_queued_when_there_is_no_semantic_search(): void
    {
        Queue::fake();

        $lesson = $this->lessonWithVideo();

        $this->actingAs($this->author())
            ->postJson(route('lms.transcripts.store', $lesson), [
                'source_kind' => AnswerSource::Video->value,
                'text' => 'Второй слой сохнет четыре часа.',
            ])
            ->assertCreated();

        Queue::assertNothingPushed();
    }

    /* ---------- ради чего всё ---------- */

    /**
     * Главное следствие переделки: сказанное в записи стало находимым, и у
     * найденного есть секунда. Раньше урок, где всё существенное произнесено
     * голосом, для консультанта был пустым.
     */
    public function test_the_consultant_answers_from_the_video_and_points_at_the_second(): void
    {
        $lesson = $this->lessonWithVideo();

        $this->actingAs($this->author())
            ->postJson(route('lms.transcripts.store', $lesson), [
                'source_kind' => AnswerSource::Video->value,
                'text' => '00:12:35 Второй слой краски сохнет не менее четырёх часов при двадцати градусах.',
            ])
            ->assertCreated();

        $this->fakeModel(FakeAnthropicTransport::replying('Четыре часа [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Сколько сохнет второй слой краски?'])
            ->assertOk()
            ->assertJsonPath('data.sources.0.location.kind', AnswerSource::Video->value)
            ->assertJsonPath('data.sources.0.location.seconds', 755)
            ->assertJsonPath('data.sources.0.location.label', 'Видео урока, 12:35');
    }

    /* ---------- helpers ---------- */

    private function fakeModel(FakeAnthropicTransport $transport): FakeAnthropicTransport
    {
        $this->app->instance(Client::class, new Client(
            apiKey: 'test-key',
            requestOptions: RequestOptions::with(transporter: $transport, maxRetries: 0),
        ));

        return $transport;
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

    private function lessonWithVideo(): Lesson
    {
        $lesson = $this->lessonWithArticle();
        $lesson->forceFill(['video_url' => 'https://youtu.be/xxxxxxxxxxx'])->save();

        return $lesson->refresh();
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
