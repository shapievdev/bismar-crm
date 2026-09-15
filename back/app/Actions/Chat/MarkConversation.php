<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;

/**
 * Личные отметки на разговоре: приглушить и поднять наверх.
 *
 * Обе живут в строке участия, а не в переписке: группа, которую один держит
 * закреплённой, второму просто мешает, и решать это за обоих нельзя.
 *
 * Обе — время, а не флаг. «Когда приглушил» однажды понадобится, чтобы отличить
 * молчащий с прошлого года разговор от приглушённого вчера; порядок закреплённых
 * — уже сейчас: поднятое последним стоит первым, как в телеграме.
 */
final readonly class MarkConversation
{
    public function mute(Conversation $conversation, User $person, bool $muted): ConversationParticipant
    {
        return $this->set($conversation, $person, ['muted_at' => $muted ? now() : null]);
    }

    public function pin(Conversation $conversation, User $person, bool $pinned): ConversationParticipant
    {
        return $this->set($conversation, $person, ['pinned_at' => $pinned ? now() : null]);
    }

    /**
     * @param  array<string, mixed>  $marks
     */
    private function set(Conversation $conversation, User $person, array $marks): ConversationParticipant
    {
        $conversation->participants()->updateExistingPivot($person->getKey(), $marks);

        // Перечитываем, а не собираем ответ из того, что записали: у строки
        // участия есть и другие отметки, и экран получает её целиком.
        return $conversation->membershipOf($person) ?? new ConversationParticipant;
    }
}
