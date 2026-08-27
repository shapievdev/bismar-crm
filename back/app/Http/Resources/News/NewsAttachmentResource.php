<?php

declare(strict_types=1);

namespace App\Http\Resources\News;

use App\Models\NewsAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NewsAttachment
 */
final class NewsAttachmentResource extends JsonResource
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

            // Подписанная и недолгая: адрес годен на час, номер — навсегда.
            // Статья хранит номер, а этот адрес подставляют на экране.
            'url' => $this->temporaryUrl(),
            'opens_inline' => $this->opensInline(),
        ];
    }
}
