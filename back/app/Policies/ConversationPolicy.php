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
 *
 * Общий чат компании не принадлежит никому: его не ведут, из него не выходят
 * и его не удаляют — состав у него свой, его ведёт приложение. Всё, что об
 * этом говорит политика, упирается в `isGeneral()`, а не в название или номер
 * записи.
 *
 * Лента уведомлений — переписка с приложением: читать её можно, говорить в
 * ней нельзя никому. Убрать у себя — можно: прочитанные уведомления тем и
 * кончаются.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->includes($user);
    }

    public function speak(User $user, Conversation $conversation): bool
    {
        return ! $conversation->isSystem() && $conversation->includes($user);
    }

    public function manage(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup()
            && ! $conversation->isGeneral()
            && ! $conversation->isSystem()
            && $conversation->created_by_id === $user->getKey()
            && $conversation->includes($user);
    }

    /**
     * Выйти можно только из группы: личная переписка — разговор двоих, и
     * «выйти» из него значит стереть его у собеседника. Из общего чата не
     * выходят вовсе — он на то и общий.
     */
    public function leave(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup()
            && ! $conversation->isGeneral()
            && ! $conversation->isSystem()
            && $conversation->includes($user);
    }

    /** Убрать у себя можно всякую свою переписку — и личную, и группу. */
    public function clear(User $user, Conversation $conversation): bool
    {
        return $conversation->includes($user);
    }

    /**
     * Удалить у всех.
     *
     * У личной переписки — любой из двоих: разговор принадлежит обоим поровну,
     * и права здесь у них одинаковые. У группы — только тот, кто её завёл: она
     * пережила бы уход любого другого, и стирать сказанное десятком людей
     * одному из них не по чину. Остальным — выход.
     */
    public function deleteForEveryone(User $user, Conversation $conversation): bool
    {
        if ($conversation->isGeneral() || $conversation->isSystem()) {
            return false;
        }

        return $conversation->isGroup()
            ? $this->manage($user, $conversation)
            : $conversation->includes($user);
    }
}
