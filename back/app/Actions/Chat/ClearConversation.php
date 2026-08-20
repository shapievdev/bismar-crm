<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Events\Chat\ConversationRemoved;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Chat\Announcement;

/**
 * Убирает переписку у одного человека.
 *
 * Ничего не удаляет: у собеседника разговор остаётся целиком, и стирать
 * сказанное обоими из-за решения одного нельзя. Ставится метка времени — с неё
 * для этого человека переписка начинается заново.
 *
 * Пока в ней молчат, её нет и в списке: показывать пустую строчку разговора,
 * который человек только что убрал, — значит не убрать его вовсе. Ответят —
 * вернётся, но уже без прошлого.
 *
 * Прочитанное двигается туда же: непрочитанное из убранной истории иначе
 * осталось бы висеть цифрой над разговором, в котором ничего не показать.
 */
final readonly class ClearConversation
{
    public function handle(Conversation $conversation, User $reader): void
    {
        $clearedAt = now();

        $conversation->participants()->updateExistingPivot($reader->getKey(), [
            'cleared_at' => $clearedAt,
            'last_read_at' => $clearedAt,
        ]);

        // Только себе: у остальных ничего не изменилось. Своей же второй
        // вкладке — изменилось, и она об этом узнаёт.
        Announcement::attempt(new ConversationRemoved(
            (int) $conversation->getKey(),
            [(int) $reader->getKey()],
        ));
    }
}
