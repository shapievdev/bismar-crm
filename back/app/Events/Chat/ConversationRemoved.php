<?php

declare(strict_types=1);

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Переписки больше нет — у того, кто её убрал, либо у всех сразу.
 *
 * Уходит только в личные каналы: канал самой переписки к этому времени уже
 * некому слушать по праву — участников у неё может не остаться вовсе, — а на
 * личный подписан каждый и всё время, пока открыта вкладка. Так о пропаже
 * узнают и те, у кого разговор был закрыт, и вторая вкладка того, кто удалял.
 *
 * Ни модели, ни имени внутри: переписка к моменту рассылки может быть уже
 * удалена, и восстанавливать её из события было бы не по чему. Приложению
 * довольно номера — по нему оно убирает строчку из списка и закрывает ленту.
 */
final class ConversationRemoved implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<int>  $recipients  кому сообщить о пропаже
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly array $recipients,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            static fn (int $person): PrivateChannel => new PrivateChannel('users.'.$person),
            $this->recipients,
        );
    }

    public function broadcastAs(): string
    {
        return 'conversation.removed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }
}
