<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
final class CategoryResource extends JsonResource
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
            // `descendants` is the recursive eager load and `children` the
            // shallow one; either fills the same key, so the client always
            // sees a tree regardless of how the query was built.
            'children' => CategoryResource::collection($this->loadedChildren()),
            'courses_count' => $this->whenCounted('courses'),
        ];
    }

    /**
     * @return Collection<int, Category>
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
