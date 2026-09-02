<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\AttachmentSource;
use App\Support\Lms\AttachmentDelivery;
use App\Support\Lms\GoogleDrive;
use App\Support\Lms\StoredFiles;
use Illuminate\Support\Facades\Storage;

/**
 * Приложенный файл — наш или с Google Диска.
 *
 * Урок и документ прикладывают файлы одинаково, поэтому и рассуждение о них
 * одно. Разница между родами вложений спрятана здесь: снаружи спрашивают адрес,
 * а не «в корзине он или у Google».
 *
 * @property-read AttachmentSource $source
 * @property-read ?string $external_id
 * @property-read ?string $disk
 * @property-read ?string $path
 * @property-read ?string $mime_type
 * @property-read string $name
 */
trait AttachedFile
{
    /**
     * Загруженный к нам файл — случай по умолчанию, и знать об этом должна не
     * только база: только что созданная запись отвечает о себе сразу, не
     * дожидаясь, пока её перечитают со значением колонки.
     *
     * Прочитанная из базы запись это значение перекрывает своим, а переданное
     * в конструктор — тем более: и то и другое приходит после.
     */
    public function initializeAttachedFile(): void
    {
        $this->attributes['source'] = AttachmentSource::Storage->value;
    }

    public function isFromDrive(): bool
    {
        return $this->source->isGoogleDrive();
    }

    /**
     * Куда ведёт имя файла на экране.
     *
     * У нашего файла это короткоживущая подписанная ссылка: объекты в корзине
     * остаются закрытыми, а утёкшая ссылка перестаёт работать, а не открывает
     * доступ навсегда. У файла с Диска — он сам на Диске: там же и просят
     * доступ, если его не дали.
     */
    public function url(): string
    {
        if ($this->isFromDrive()) {
            return app(GoogleDrive::class)->viewUrl((string) $this->external_id, $this->mime_type);
        }

        $delivery = app(AttachmentDelivery::class);

        return Storage::disk((string) $this->disk)->temporaryUrl(
            (string) $this->path,
            now()->addMinutes(config('lms.attachment_url_ttl_minutes')),
            [
                // Подписано в саму ссылку: браузер нельзя уговорить показать
                // на месте файл, который показывать нельзя.
                'ResponseContentDisposition' => $delivery->contentDisposition($this->mime_type, $this->name),
            ],
        );
    }

    /**
     * Адрес для рамки на странице — только у файла с Диска.
     *
     * Наш файл рамки не требует: браузер показывает его сам по подписанной
     * ссылке, а что показывать на месте, а что отдавать файлом, решает
     * AttachmentDelivery.
     */
    public function embedUrl(): ?string
    {
        return $this->isFromDrive()
            ? app(GoogleDrive::class)->embedUrl((string) $this->external_id, $this->mime_type)
            : null;
    }

    /** Показывается ли на месте, а не скачивается. */
    public function opensInline(): bool
    {
        return $this->isFromDrive() || app(AttachmentDelivery::class)->isInline($this->mime_type);
    }

    /**
     * Убирает объект из хранилища — вслед за удалённой записью.
     *
     * У файла с Диска убирать нечего: он не наш, и отвязать его от урока — не
     * то же самое, что стереть у автора с Диска.
     */
    public function deleteFromStorage(): void
    {
        if ($this->isFromDrive()) {
            return;
        }

        // Запись уже удалена; недоступное хранилище не должно превращать это в
        // ошибку у того, кто удалял, — см. StoredFiles.
        StoredFiles::discard((string) $this->disk, $this->path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['source' => AttachmentSource::class];
    }
}
