<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Общее у всего, что случается с сообщением: сказано, изменено, удалено.
 *
 * Уходит сразу по двум адресам, и это не дублирование. Канал переписки нужен
 * тому, у кого она открыта: лента должна измениться на глазах. Личный канал —
 * всем прочим: у них переписка закрыта, но список и счётчик непрочитанного
 * обязаны обновиться, а подписываться ради этого на все свои переписки разом
 * значит держать полсотни подписок вместо одной.
 *
 * Правка и удаление ходят теми же двумя каналами, а не одним: изменённой могла
 * оказаться последняя реплика, и тогда меняется ещё и строчка в списке.
 *
 * ShouldBroadcastNow, а не в очередь: очередь в этом развёртывании работником
 * не разбирается (см. Jobs\EmbedLesson), и событие долетало бы до собеседника,
 * когда о нём вспомнят. Стоит это одного запроса к сокет-серверу внутри того же
 * обращения — единиц миллисекунд.
 */
abstract class MessageEvent implements ShouldBroadcastNow
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
}