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
 * Файл, приложенный к сообщению.
 *
 * Всё как у вложений урока — тот же бакет, те же подписанные ссылки, та же
 * оговорка про диск в строке.
 */
#[Fillable(['message_id', 'disk', 'path', 'name', 'mime_type', 'size', 'is_voice', 'duration_ms', 'waveform'])]
class MessageAttachment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_voice' => 'boolean',
            'duration_ms' => 'integer',

            // Огибающая записи: по числу на столбик волны.
            'waveform' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Недолгая подписанная ссылка: бакет остаётся закрытым, а утёкшая ссылка
     * перестаёт работать вместо того, чтобы открывать файл навсегда.
     */
    public function temporaryUrl(): string
    {
        $delivery = app(AttachmentDelivery::class);

        return Storage::disk($this->disk)->temporaryUrl(
            $this->path,
            now()->addMinutes((int) config('lms.attachment_url_ttl_minutes')),
            [
                'ResponseContentDisposition' => $delivery->contentDisposition($this->mime_type, $this->name),
            ],
        );
    }

    /** Показывать ли файл прямо в переписке — картинку показываем, архив нет. */
    public function opensInline(): bool
    {
        return app(AttachmentDelivery::class)->isInline($this->mime_type);
    }

    /**
     * Голосовое — то, что записали здесь же, а не приложили файлом.
     *
     * Признак, а не тип: присланная почтой запись совещания — тоже звук, но
     * показывать её волной с кнопкой «играть» неправильно, это документ.
     */
    public function isVoice(): bool
    {
        return $this->is_voice === true;
    }

    public function deleteFromStorage(): void
    {
        StoredFiles::discard($this->disk, $this->path);
    }
}
