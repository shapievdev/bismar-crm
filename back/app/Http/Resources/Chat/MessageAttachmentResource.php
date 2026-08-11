<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @mixin MessageAttachment
 */
final class MessageAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'opens_inline' => $this->opensInline(),
            'url' => $this->signedUrl(),
        ];
    }

    /**
     * Хранилище бывает недоступно, а переписка от этого читаться не перестаёт:
     * сообщение покажется без ссылки на файл.
     */
    private function signedUrl(): ?string
    {
        try {
            return $this->temporaryUrl();
        } catch (Throwable $exception) {
            Log::warning('Ссылка на вложение не подписана.', ['exception' => $exception]);

            return null;
        }
    }
}
