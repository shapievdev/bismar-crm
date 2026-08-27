<?php

declare(strict_types=1);

namespace App\Actions\News;

use App\Models\News;
use App\Models\NewsAttachment;
use Illuminate\Http\UploadedFile;

/**
 * Кладёт файл при новости.
 *
 * Сюда же попадает то, что автор вставил прямо в статью, — картинка и видео
 * хранятся обычным вложением, чтобы у них был номер, переживающий подписанную
 * ссылку, и чтобы они уходили вместе с новостью.
 */
final readonly class StoreNewsAttachment
{
    private const DISK = 's3';

    public function handle(News $news, UploadedFile $file, ?string $description = null): NewsAttachment
    {
        // Имя объекту даёт Laravel: враждебный клиент не выбирает ключ и не
        // перезапишет чужой файл.
        $path = $file->store("news/{$news->getKey()}", self::DISK);

        return $news->attachments()->create([
            'disk' => self::DISK,
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'description' => $description,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);
    }
}
