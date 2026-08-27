<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Regulation;
use App\Models\RegulationAttachment;
use Illuminate\Http\UploadedFile;

/**
 * Кладёт файл при регламенте.
 *
 * Сюда же попадает то, что автор вставил прямо в статью, — картинка и видео
 * хранятся обычным вложением, чтобы у них был номер, переживающий подписанную
 * ссылку, и чтобы они уходили вместе с регламентом.
 */
final readonly class StoreRegulationAttachment
{
    private const DISK = 's3';

    public function handle(
        Regulation $regulation,
        UploadedFile $file,
        ?string $description = null,
    ): RegulationAttachment {
        // Имя объекту даёт Laravel: враждебный клиент не выбирает ключ и не
        // перезапишет чужой файл.
        $path = $file->store("regulations/{$regulation->getKey()}", self::DISK);

        return $regulation->attachments()->create([
            'disk' => self::DISK,
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'description' => $description,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);
    }
}
