<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\KnowledgeArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin KnowledgeArticle
 */
final class KnowledgeArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            // Listings omit the body; only the detail endpoint loads it.
            'content' => $this->when($this->shouldIncludeContent($request), fn (): string => $this->content),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'category' => KnowledgeCategoryResource::make($this->whenLoaded('category')),
            'author' => $this->whenLoaded('author', fn (): ?array => $this->author === null ? null : [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ]),
        ];
    }

    private function shouldIncludeContent(Request $request): bool
    {
        return $request->routeIs('knowledge.articles.show')
            || $request->routeIs('knowledge.articles.store')
            || $request->routeIs('knowledge.articles.update');
    }
}
