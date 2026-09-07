<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Actions\Chat\SayInConversation;
use App\Actions\Chat\StartConversation;
use App\Enums\AppealReason;
use App\Models\Message;
use App\Models\User;
use App\Support\Lms\MaterialAppeal;

/**
 * Замечание к материалу уходит письмом тому, кто его правит.
 *
 * Отдельного ящика для замечаний нет намеренно (решение пользователя
 * 2026-09-06): у автора уже есть место, куда ему пишут коллеги, и второе
 * такое же — это второе место, куда надо не забыть заглянуть. Разговор
 * начинается сам собой: сотрудник дописывает, автор отвечает, и всё это
 * лежит там же, где остальная переписка.
 *
 * Личная переписка у пары одна навсегда, поэтому третье замечание попадает в
 * тот же разговор, что и первое, — а карточка над каждой репликой говорит, с
 * какого материала оно пришло.
 */
final readonly class AppealToAuthor
{
    public function __construct(
        private StartConversation $conversations,
        private SayInConversation $say,
    ) {}

    public function handle(
        User $reader,
        User $recipient,
        MaterialAppeal $appeal,
        AppealReason $reason,
        string $body,
    ): Message {
        $conversation = $this->conversations->direct($reader, $recipient);

        return $this->say->handle(
            conversation: $conversation,
            author: $reader,
            body: $body,
            about: $appeal->snapshot($reason),
        );
    }
}
