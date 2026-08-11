<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Http\Resources\Chat\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Сказанное в переписке — тем, кто в ней состоит.
 *
 * Уходит сразу по двум адресам, и это не дублирование. Канал переписки нужен
 * тому, у кого она открыта: сообщение должно появиться в ленте. Личный канал —
 * всем прочим: у них переписка закрыта, но список и счётчик непрочитанного
 * обязаны обновиться, а подписываться ради этого на все свои переписки разом
 * значит держать полсотни подписок вместо одной.
 *
 * ShouldBroadcastNow, а не в очередь: очередь в этом развёртывании работником
 * не разбирается (см. Jobs\EmbedLesson), и сообщение долетало бы до
 * собеседника, когда о нём вспомнят. Стоит это одного запроса к сокет-серверу
 * внутри того же обращения — единиц миллисекунд.
 */
final class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Message $message) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $conversation = $this->message->conversation;

        $channels = [new PrivateChannel('conversations.'.$conversation->getKey())];

        foreach ($conversation->activeParticipants()->pluck('users.id') as $participant) {
            $channels[] = new PrivateChannel('users.'.$participant);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $message = $this->message->loadMissing(['author', 'attachments']);

        return [
            'conversation_id' => $message->conversation_id,
            'message' => MessageResource::make($message)->resolve(),
        ];
    }
}
