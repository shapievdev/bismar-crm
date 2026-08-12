<?php

declare(strict_types=1);

namespace App\Events\Chat;

/**
 * Реплику убрали — тем, у кого она на экране.
 *
 * Уходят одни лишь опознавательные знаки, без содержимого: удалённый текст не
 * должен ещё раз пройти по проводу и осесть в чужой вкладке. Что показать на
 * освободившемся месте — «сообщение удалено» там, где на него отвечали, или
 * ничего — решает уже приложение.
 */
final class MessageDeleted extends MessageEvent
{
    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->getKey(),
        ];
    }
}