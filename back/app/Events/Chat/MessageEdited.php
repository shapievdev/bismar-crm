<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Http\Resources\Chat\MessageResource;

/**
 * Реплику переписали — тем, кто мог её уже прочесть.
 *
 * Уходит целиком, а не одним новым текстом: у собеседника на экране лежит та же
 * структура, что пришла при отправке, и подменить её целиком дешевле и
 * надёжнее, чем сращивать по полям.
 */
final class MessageEdited extends MessageEvent
{
    public function broadcastAs(): string
    {
        return 'message.edited';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $message = $this->message->loadMissing(['author', 'attachments', 'replyTo.author']);

        return [
            'conversation_id' => $message->conversation_id,
            'message' => MessageResource::make($message)->resolve(),
        ];
    }
}