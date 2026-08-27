<?php

declare(strict_types=1);

namespace App\Http\Resources\News;

use App\Models\News;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin News
 */
final class NewsResource extends JsonResource
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

            // Статья едет только в карточке одной новости: в ленте из двадцати
            // она весит больше всего остального вместе взятого.
            'content_json' => $this->when($this->shouldSendContent(), fn () => $this->content_json),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_published' => $this->isPublished(),
            'published_at' => $this->published_at?->toIso8601String(),
            'is_pinned' => $this->is_pinned,

            'audience' => $this->audience->value,
            'audience_label' => $this->audience->label(),
            'requires_acknowledgement' => $this->requires_acknowledgement,

            'author' => $this->whenLoaded('author', fn (): ?array => $this->author === null ? null : [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ]),

            // Кому адресована — только на экране составителя: читателю список
            // людей, которым это тоже прислали, знать незачем.
            'recipients' => NewsPersonResource::collection($this->whenLoaded('recipients')),

            'attachments' => NewsAttachmentResource::collection($this->whenLoaded('attachments')),
            'quiz' => NewsQuizResource::make($this->whenLoaded('quiz')),

            // Проставляет контроллер: он один знает, кто спрашивает.
            'is_acknowledged' => $this->is_acknowledged,
            'acknowledged_at' => $this->acknowledged_at,

            // Сколько человек уже отметилось и сколько всего адресатов —
            // для того, кто новость ведёт.
            'acknowledged_count' => $this->whenCounted('acknowledgements'),
            'audience_size' => $this->when($this->audience_size !== null, fn () => $this->audience_size),
        ];
    }

    /**
     * Статья нужна на карточке новости и на экране правки, но не в ленте.
     */
    private function shouldSendContent(): bool
    {
        return (bool) $this->sends_content;
    }
}
