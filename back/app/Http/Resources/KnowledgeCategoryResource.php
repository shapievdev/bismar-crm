<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\KnowledgeCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin KnowledgeCategory
 */
final class KnowledgeCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'position' => $this->position,
            'articles_count' => $this->whenCounted('articles'),
        ];
    }
}
