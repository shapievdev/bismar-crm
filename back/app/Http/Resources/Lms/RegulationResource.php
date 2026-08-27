<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\Regulation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Regulation
 */
final class RegulationResource extends JsonResource
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
            'summary' => $this->summary,

            // Статья едет только с карточкой одного регламента: в каталоге из
            // двадцати она весит больше всего остального вместе взятого.
            'content_json' => $this->when((bool) $this->sends_content, fn () => $this->content_json),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_published' => $this->isPublished(),
            'published_at' => $this->published_at?->toIso8601String(),

            // Закрыт регламент или открыт — и вправе ли этот человек это менять.
            'visibility' => $this->visibility->value,
            'visibility_label' => $this->visibility->label(),
            'is_private' => $this->isPrivate(),
            'can_manage_access' => $request->user()?->can('manageAccess', $this->resource) ?? false,
            'members_count' => $this->whenCounted('members'),

            'category' => RegulationCategoryResource::make($this->whenLoaded('category')),
            'author' => $this->whenLoaded('author', fn (): ?array => $this->author === null ? null : [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ]),

            // Кому писать, если написанного не хватило.
            'experts' => CoursePersonResource::collection($this->whenLoaded('experts')),

            'attachments' => RegulationAttachmentResource::collection($this->whenLoaded('attachments')),

            // Весь прогресс, какой у регламента бывает. Проставляет контроллер:
            // он один знает, кто спрашивает.
            'is_acknowledged' => (bool) $this->is_acknowledged,
            'acknowledged_at' => $this->acknowledged_at,

            // Сколько человек уже отметилось — тому, кто регламент ведёт.
            'acknowledged_count' => $this->whenCounted('acknowledgements'),
        ];
    }
}
