<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\RegulationCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RegulationCategory
 */
final class RegulationCategoryResource extends JsonResource
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
            'parent_id' => $this->parent_id,
            // `descendants` — рекурсивная подгрузка, `children` — поверхностная;
            // обе заполняют один и тот же ключ, поэтому клиент всегда видит
            // дерево, как бы ни был собран запрос.
            'children' => RegulationCategoryResource::collection($this->loadedChildren()),
            'regulations_count' => $this->whenCounted('regulations'),
        ];
    }

    /**
     * @return Collection<int, RegulationCategory>
     */
    private function loadedChildren(): Collection
    {
        foreach (['descendants', 'children'] as $relation) {
            if ($this->resource->relationLoaded($relation)) {
                return $this->resource->getRelation($relation);
            }
        }

        return new Collection;
    }
}
