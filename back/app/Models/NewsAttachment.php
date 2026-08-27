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
 * Файл при новости: приложенный документ или картинка и видео из самой статьи.
 *
 * Устроен как вложение урока и намеренно не он: у новостей своя таблица, чтобы
 * не переводить работающую базу знаний на полиморфные связи. Общее у них —
 * правила выдачи (AttachmentDelivery) и уборка объектов (StoredFiles); это
 * рассуждения о хранилище, а не о том, к чему файл приложен.
 */
#[Fillable(['news_id', 'disk', 'path', 'name', 'description', 'mime_type', 'size'])]
class NewsAttachment extends Model
{
    /**
     * @return BelongsTo<News, $this>
     */
    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
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
        // Строку уже удалили; недоступное хранилище не должно превращать это в
        // ошибку у того, кто удалял, — см. StoredFiles.
        StoredFiles::discard($this->disk, $this->path);
    }
}
