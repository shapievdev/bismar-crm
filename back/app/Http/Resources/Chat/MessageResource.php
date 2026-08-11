<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
final class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'kind' => $this->kind->value,
            'body' => $this->body,

            // У системного сообщения автора нет, у обычного он мог уволиться:
            // в обоих случаях подписи не будет, и это разные вещи только для
            // того, кто пишет код, — читателю всё равно.
            'author' => PersonResource::make($this->whenLoaded('author')),

            'attachments' => MessageAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
