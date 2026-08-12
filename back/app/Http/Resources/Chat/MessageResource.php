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

            // Пустое поле, а не отсутствие: «не правили» — это тоже ответ, и
            // приложению не нужно догадываться, загружали ли признак.
            'edited_at' => $this->edited_at?->toIso8601String(),

            'reply_to' => $this->quotedReply(),
        ];
    }

    /**
     * Цитата над ответом: ровно столько, сколько нужно, чтобы узнать реплику.
     *
     * Целиком её не отдаём — в ленте уже лежит она сама, а в цитате длинный
     * текст всё равно обрезается. Удалённая цитата приходит помеченной и без
     * текста: показать надо, что отвечали на что-то, чего больше нет.
     *
     * @return array<string, mixed>|null
     */
    private function quotedReply(): ?array
    {
        if ($this->reply_to_id === null || ! $this->relationLoaded('replyTo')) {
            return null;
        }

        $original = $this->replyTo;

        if ($original === null) {
            return null;
        }

        return [
            'id' => $original->getKey(),
            'deleted' => $original->trashed(),
            'author' => PersonResource::make($original->relationLoaded('author') ? $original->author : null),
            'excerpt' => $original->trashed() ? null : $this->excerptOf($original),
        ];
    }

    /**
     * Короткая выжимка чужой реплики. Сообщение из одних вложений текста не
     * имеет, и вместо пустоты цитата называет, что там лежало.
     */
    private function excerptOf(Message $original): string
    {
        $body = trim((string) $original->body);

        if ($body !== '') {
            return mb_strimwidth($body, 0, 120, '…');
        }

        $count = $original->relationLoaded('attachments')
            ? $original->attachments->count()
            : $original->attachments()->count();

        return $count > 0 ? 'Вложение' : '';
    }
}
