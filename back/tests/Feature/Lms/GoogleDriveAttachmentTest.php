<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\AttachmentSource;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\Regulation;
use App\Models\RegulationAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Файл, оставшийся жить на Google Диске.
 *
 * Инструкции компании годами лежат там, и требовать второй копии здесь значит
 * завести две правды: поправят на Диске — здесь останется вчерашнее. Поэтому мы
 * храним только номер файла, а адрес для рамки собираем из него сами: адресу,
 * присланному с экрана, в `src` рамки не место — так внутри нашей страницы
 * показался бы чужой сайт.
 */
final class GoogleDriveAttachmentTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Похожий на настоящий номер файла у Google. */
    private const FILE_ID = '1A2b3C4d5E6f7G8h9I0jKlMnOpQrStUvWx';

    public function test_an_author_attaches_a_file_from_the_drive_to_a_lesson(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->author())
            ->postJson(route('lms.attachments.drive', $lesson), [
                'external_id' => self::FILE_ID,
                'name' => 'Инструкция по отгрузке.pdf',
                'mime_type' => 'application/pdf',
                'description' => 'Как оформить отгрузку',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Инструкция по отгрузке.pdf')
            ->assertJsonPath('data.source', AttachmentSource::GoogleDrive->value)
            // Адрес собран нами из номера, а не взят с экрана.
            ->assertJsonPath('data.embed_url', 'https://drive.google.com/file/d/'.self::FILE_ID.'/preview')
            ->assertJsonPath('data.url', 'https://drive.google.com/file/d/'.self::FILE_ID.'/view')
            // Показывается на месте, а не скачивается: скачивать нечего.
            ->assertJsonPath('data.opens_inline', true);

        $attachment = LessonAttachment::query()->sole();

        $this->assertSame(AttachmentSource::GoogleDrive, $attachment->source);
        $this->assertSame(self::FILE_ID, $attachment->external_id);
        // Ни корзины, ни ключа объекта: файл не наш.
        $this->assertNull($attachment->disk);
        $this->assertNull($attachment->path);
    }

    /** У Документов, Таблиц и Презентаций свой просмотр — по виду файла. */
    public function test_a_google_document_gets_its_own_viewer(): void
    {
        $this->actingAs($this->author())
            ->postJson(route('lms.attachments.drive', $this->lesson()), [
                'external_id' => self::FILE_ID,
                'name' => 'Регламент склада',
                'mime_type' => 'application/vnd.google-apps.document',
            ])
            ->assertCreated()
            ->assertJsonPath('data.embed_url', 'https://docs.google.com/document/d/'.self::FILE_ID.'/preview')
            ->assertJsonPath('data.url', 'https://docs.google.com/document/d/'.self::FILE_ID.'/edit');
    }

    /**
     * Номер приходит из браузера, и всё, что на него не похоже, отвергается на
     * входе: из него собирается адрес рамки.
     */
    public function test_something_that_is_not_a_file_id_is_refused(): void
    {
        foreach (['https://evil.example/page', 'короткий', '../../etc/passwd'] as $id) {
            $this->actingAs($this->author())
                ->postJson(route('lms.attachments.drive', $this->lesson()), [
                    'external_id' => $id,
                    'name' => 'Файл',
                ])
                ->assertJsonValidationErrorFor('external_id');
        }

        $this->assertSame(0, LessonAttachment::query()->count());
    }

    /** Прикладывать файлы — правка урока, и право на неё то же самое. */
    public function test_a_learner_cannot_attach_a_file_from_the_drive(): void
    {
        $this->actingAs($this->learner())
            ->postJson(route('lms.attachments.drive', $this->lesson()), [
                'external_id' => self::FILE_ID,
                'name' => 'Файл',
            ])
            ->assertForbidden();
    }

    /**
     * Список файлов урока — не история нажатий: второй выбор того же файла
     * поправит имя, но не заведёт вторую строку.
     */
    public function test_choosing_the_same_file_twice_does_not_double_it(): void
    {
        $lesson = $this->lesson();
        $author = $this->author();

        $this->actingAs($author)
            ->postJson(route('lms.attachments.drive', $lesson), [
                'external_id' => self::FILE_ID,
                'name' => 'Инструкция.pdf',
                'description' => 'Как оформить отгрузку',
            ])
            ->assertCreated();

        $this->actingAs($author)
            ->postJson(route('lms.attachments.drive', $lesson), [
                'external_id' => self::FILE_ID,
                'name' => 'Инструкция (новая редакция).pdf',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Инструкция (новая редакция).pdf')
            // Подпись — работа автора, и пустая она означает «ничего не написал
            // сейчас», а не «сотри написанное раньше».
            ->assertJsonPath('data.description', 'Как оформить отгрузку');

        $this->assertSame(1, LessonAttachment::query()->count());
    }

    /**
     * Удаление отвязывает файл от урока и только: стереть его у автора с Диска
     * мы не вправе и не можем.
     */
    public function test_removing_the_attachment_leaves_the_file_on_the_drive(): void
    {
        $lesson = $this->lesson();
        $author = $this->author();

        $this->actingAs($author)
            ->postJson(route('lms.attachments.drive', $lesson), [
                'external_id' => self::FILE_ID,
                'name' => 'Инструкция.pdf',
            ])
            ->assertCreated();

        $this->actingAs($author)
            ->deleteJson(route('lms.attachments.destroy', LessonAttachment::query()->sole()))
            ->assertNoContent();

        $this->assertSame(0, LessonAttachment::query()->count());
    }

    public function test_an_author_attaches_a_file_from_the_drive_to_a_document(): void
    {
        $document = Regulation::factory()->published()->create();

        $this->actingAs($this->author())
            ->postJson(route('lms.regulations.attachments.drive', $document), [
                'external_id' => self::FILE_ID,
                'name' => 'Бланк заявления',
                'mime_type' => 'application/vnd.google-apps.spreadsheet',
            ])
            ->assertCreated()
            ->assertJsonPath('data.source', AttachmentSource::GoogleDrive->value)
            ->assertJsonPath('data.embed_url', 'https://docs.google.com/spreadsheets/d/'.self::FILE_ID.'/preview');

        $this->assertSame(1, RegulationAttachment::query()->count());
    }

    private function lesson(): Lesson
    {
        return Course::factory()->withLessons(1)->create()->lessons()->firstOrFail();
    }
}
