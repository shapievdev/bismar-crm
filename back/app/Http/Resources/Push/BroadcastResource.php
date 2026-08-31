<?php

declare(strict_types=1);

namespace App\Http\Resources\Push;

use App\Models\PushBroadcast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Отправленная рассылка в истории.
 *
 * @mixin PushBroadcast
 */
final class BroadcastResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,

            'audience' => $this->audience->value,
            'audience_label' => $this->audience->label(),
            'department' => $this->whenLoaded('department', fn (): ?string => $this->department?->name),

            // Людей и устройств — снимком на день отправки: и те, и другие с тех
            // пор могли измениться.
            'recipients_count' => (int) $this->recipients_count,
            'devices_count' => (int) $this->devices_count,

            'author' => $this->whenLoaded('author', fn (): ?string => $this->author?->name),
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
