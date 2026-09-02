<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\AttachmentSource;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\Regulation;
use App\Models\RegulationAttachment;

/**
 * Прикладывает к уроку или документу файл, лежащий на Google Диске.
 *
 * Ничего не скачивает: копия жила бы своей жизнью и расходилась бы с
 * оригиналом, а прикладывают файл с Диска как раз затем, чтобы правка там была
 * видна здесь. Мы храним номер файла и то, чем его подписать в списке.
 *
 * Дважды приложенный файл не двоится: список файлов урока — не история
 * нажатий, и вторая строка о том же самом только мешала бы. Повторный выбор
 * поэтому возвращает уже существующую запись, а подпись у неё правят там же,
 * где у всех прочих.
 */
final readonly class AttachDriveFile
{
    /**
     * @param  array{external_id: string, name: string, mime_type?: ?string, description?: ?string}  $file
     */
    public function handle(Lesson|Regulation $owner, array $file): LessonAttachment|RegulationAttachment
    {
        $attributes = [
            'source' => AttachmentSource::GoogleDrive,
            'external_id' => $file['external_id'],
        ];

        /** @var LessonAttachment|RegulationAttachment $attachment */
        $attachment = $owner->attachments()->firstOrNew($attributes);

        $attachment->fill([
            // Имя берётся с Диска заново: файл там могли переименовать, и в
            // списке должно стоять то, что автор увидит, открыв его.
            'name' => $file['name'],
            'mime_type' => $file['mime_type'] ?? null,
            // Размер файла на Диске нам неизвестен и не нужен: скачивать его
            // мы не собираемся, а на экране у такой строки стоит не «12 МБ», а
            // «файл на Google Диске».
            'size' => 0,
        ]);

        // Подпись — работа автора, а не свойство файла: пустая при повторном
        // выборе она означает «ничего не написал сейчас», а не «сотри то, что
        // было написано раньше».
        if (($file['description'] ?? null) !== null) {
            $attachment->description = $file['description'];
        }

        $attachment->save();

        return $attachment;
    }
}
