<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Events\Chat\MessageReacted;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use App\Support\Chat\Announcement;
use Illuminate\Support\Facades\DB;

/**
 * Отклик на реплику: поставить, сменить, снять.
 *
 * Одно действие на все три случая, потому что для человека это одно движение —
 * он нажимает на знак. Тем же знаком отклик снимается, другим — заменяется:
 * второго мнения у одного человека об одной реплике не бывает.
 *
 * Вставка идёт через upsert, а не «посмотреть и решить»: два быстрых нажатия
 * подряд — обычное дело на телефоне, и между «не нашли» и «создаём» помещается
 * второе из них. Уникальность пары в базе превратила бы это в пятисотую.
 */
final readonly class ReactToMessage
{
    /**
     * @return string|null поставленный знак или null, если отклик сняли
     */
    public function handle(Message $message, User $person, string $emoji): ?string
    {
        $standing = DB::transaction(function () use ($message, $person, $emoji): ?string {
            $current = MessageReaction::query()
                ->where('message_id', $message->getKey())
                ->where('user_id', $person->getKey())
                ->first();

            if ($current?->emoji === $emoji) {
                $current->delete();

                return null;
            }

            MessageReaction::query()->upsert(
                [[
                    'message_id' => $message->getKey(),
                    'user_id' => $person->getKey(),
                    'emoji' => $emoji,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['message_id', 'user_id'],
                ['emoji', 'updated_at'],
            );

            return $emoji;
        });

        Announcement::attempt(new MessageReacted($message->load('reactions.person')));

        return $standing;
    }
}
