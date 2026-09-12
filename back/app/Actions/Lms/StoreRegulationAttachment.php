<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Regulation;
use App\Models\RegulationAttachment;
use App\Models\RegulationVersion;
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

    /**
     * @param  ?RegulationVersion  $version  версия, к которой файл приложен;
     *                                       null — общая, то есть сам документ
     */
    public function handle(
        Regulation $regulation,
        UploadedFile $file,
        ?string $description = null,
        ?RegulationVersion $version = null,
    ): RegulationAttachment {
        // Имя объекту даёт Laravel: враждебный клиент не выбирает ключ и не
        // перезапишет чужой файл.
        $path = $file->store("regulations/{$regulation->getKey()}", self::DISK);

        // Номер документа проставляется и у файла версии: отбор в каталоге и
        // уборка за удалённым идут по документу, и версия их не заменяет.
        return RegulationAttachment::create([
            'regulation_id' => $regulation->getKey(),
            'version_id' => $version?->getKey(),
            'disk' => self::DISK,
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'description' => $description,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);
    }
}
