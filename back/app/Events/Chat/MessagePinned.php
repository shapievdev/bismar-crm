<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Http\Resources\Chat\MessageResource;

/**
 * Реплику подняли наверх переписки — или сняли оттуда.
 *
 * Уходит целиком, вместе с самим сообщением: полосу над лентой надо чем-то
 * заполнить, а искать эту реплику у себя вкладке может быть негде — закрепить
 * можно и то, что уехало на тысячу сообщений назад.
 */
final class MessagePinned extends MessageEvent
{
    public function broadcastAs(): string
    {
        return 'message.pinned';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $message = $this->message->loadMissing(['author', 'attachments', 'replyTo.author', 'reactions.person']);

        return [
            'conversation_id' => $message->conversation_id,
            'message' => MessageResource::make($message)->resolve(),
            'pinned' => $message->isPinned(),
        ];
    }
}
