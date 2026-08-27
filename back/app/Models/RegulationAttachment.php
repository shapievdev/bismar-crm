<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Lms\AttachmentDelivery;
use App\Support\Lms\StoredFiles;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Файл при регламенте: приложенный документ или картинка и видео из статьи.
 *
 * Правила выдачи (AttachmentDelivery) и уборка объектов (StoredFiles) общие с
 * уроком и новостью — это рассуждения о хранилище, а не о том, к чему файл
 * приложен.
 */
#[Fillable(['regulation_id', 'disk', 'path', 'name', 'description', 'mime_type', 'size'])]
class RegulationAttachment extends Model
{
    /**
     * @return BelongsTo<Regulation, $this>
     */
    public function regulation(): BelongsTo
    {
        return $this->belongsTo(Regulation::class);
    }

    /**
     * Короткоживущая подписанная ссылка: объекты в корзине остаются закрытыми,
     * а утёкшая ссылка перестаёт работать, а не открывает доступ навсегда.
     */
    public function temporaryUrl(): string
    {
        $delivery = app(AttachmentDelivery::class);

        return Storage::disk($this->disk)->temporaryUrl(
            $this->path,
            now()->addMinutes(config('lms.attachment_url_ttl_minutes')),
            [
                // Подписано в саму ссылку: браузер нельзя уговорить показать
                // на месте файл, который показывать нельзя.
                'ResponseContentDisposition' => $delivery->contentDisposition($this->mime_type, $this->name),
            ],
        );
    }

    public function opensInline(): bool
    {
        return app(AttachmentDelivery::class)->isInline($this->mime_type);
    }

    public function deleteFromStorage(): void
    {
        StoredFiles::discard($this->disk, $this->path);
    }
}
