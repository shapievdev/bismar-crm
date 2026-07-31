<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\LessonAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LessonAttachment
 */
final class LessonAttachmentResource extends JsonResource
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
            // Signed and short-lived, so the bucket itself stays private.
            'url' => $this->temporaryUrl(),
        ];
    }
}
