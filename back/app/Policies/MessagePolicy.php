<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

/**
 * Кому что можно с уже сказанной репликой.
 *
 * Правит только автор, и только свои слова: подпись под сообщением — это
 * утверждение, что сказал именно он, и право переписать чужую реплику сделало
 * бы подпись бессмысленной. Даже заведший группу этого не может.
 *
 * Удаляет автор — и тот, кто группу завёл: за порядок в общем разговоре
 * отвечает он, и убрать оттуда чужое ему нужнее, чем автору сохранить.
 *
 * Системные отметки («добавил», «вышел») не трогает никто: их писал не человек,
 * и переписывать историю состава нельзя.
 */
class MessagePolicy
{
    public function update(User $user, Message $message): bool
    {
        return ! $message->isSystem()
            && ! $message->trashed()
            && $message->user_id === $user->getKey()
            && $message->conversation->includes($user);
    }

    public function delete(User $user, Message $message): bool
    {
        if ($message->isSystem() || $message->trashed()) {
            return false;
        }

        $conversation = $message->conversation;

        if (! $conversation->includes($user)) {
            return false;
        }

        return $message->user_id === $user->getKey()
            || $user->can('manage', $conversation);
    }
}