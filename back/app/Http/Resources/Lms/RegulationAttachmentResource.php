<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\RegulationAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RegulationAttachment
 */
final class RegulationAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'mime_type' => $this->mime_type,
            'size' => $this->size,

            // Наш файл или лежащий на Google Диске: экран рисует их по-разному,
            // и это единственное, по чему он их различает.
            'source' => $this->source->value,

            // Подписанная и недолгая: адрес годен на час, номер — навсегда.
            // Статья хранит номер, а этот адрес подставляют на экране. У файла
            // с Диска — он сам на Диске: туда идут за доступом.
            'url' => $this->url(),

            // Адрес для рамки — только у файла с Диска, см. AttachedFile.
            'embed_url' => $this->embedUrl(),
            'opens_inline' => $this->opensInline(),
        ];
    }
}
