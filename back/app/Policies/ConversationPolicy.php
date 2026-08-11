<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Кому что можно в переписке.
 *
 * Читать и писать — тем, кто в ней состоит, и никому больше: администратор
 * проходит Gate::before и здесь, но это уже вопрос к тому, кого назначают
 * администратором, а не к переписке.
 *
 * Менять состав и название — тому, кто группу завёл. Иначе всякий участник
 * может добавить в чужой разговор кого угодно.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->includes($user);
    }

    public function speak(User $user, Conversation $conversation): bool
    {
        return $conversation->includes($user);
    }

    public function manage(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup()
            && $conversation->created_by_id === $user->getKey()
            && $conversation->includes($user);
    }

    /**
     * Выйти можно только из группы: личная переписка — разговор двоих, и
     * «выйти» из него значит стереть его у собеседника.
     */
    public function leave(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup() && $conversation->includes($user);
    }
}
