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

    /**
     * Откликнуться знаком может всякий, кто в разговоре состоит.
     *
     * Своё — тоже: отметить собственную реплику «сделано» ничем не хуже, чем
     * ответить на неё словом, и запрещать это не за что.
     */
    public function react(User $user, Message $message): bool
    {
        return ! $message->isSystem()
            && ! $message->trashed()
            && $message->conversation->includes($user);
    }

    /**
     * Закрепление общее, и потому право на него уже права ответить.
     *
     * В личной переписке закрепляет любой из двоих: полоса наверху одна на них
     * же. В группе — только заведший её: закреплённое видят все, и всякий
     * участник, поднимающий наверх своё, превращает полосу в ещё одну ленту.
     */
    public function pin(User $user, Message $message): bool
    {
        if ($message->isSystem() || $message->trashed()) {
            return false;
        }

        $conversation = $message->conversation;

        if (! $conversation->includes($user)) {
            return false;
        }

        return ! $conversation->isGroup() || $user->can('manage', $conversation);
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