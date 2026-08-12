<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Events\Chat\MessagesRead;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Chat\Announcement;

/**
 * Отмечает переписку прочитанной и говорит об этом собеседнику.
 *
 * Отметка — одно время на всю переписку, а не строка на каждое сообщение:
 * читают их подряд, и «дочитал до сих пор» описывает это точнее и дешевле, чем
 * список галочек, растущий вместе с перепиской.
 */
final readonly class MarkConversationRead
{
    public function handle(Conversation $conversation, User $reader): string
    {
        $readAt = now();

        $conversation->participants()->updateExistingPivot($reader->getKey(), [
            'last_read_at' => $readAt,
        ]);

        Announcement::attempt(new MessagesRead($conversation, $reader, $readAt->toIso8601String()));

        return $readAt->toIso8601String();
    }
}
