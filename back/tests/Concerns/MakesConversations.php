<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\ConversationKind;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

/**
 * Разговоры, с которых начинается почти всякая проверка мессенджера.
 *
 * Собраны здесь, а не в каждом файле проверок по копии: их уже с полдесятка, и
 * заводились они одинаково — а расходиться начали бы в тот день, когда у
 * переписки появится ещё одно обязательное поле.
 */
trait MakesConversations
{
    protected function employee(): User
    {
        return User::factory()->create();
    }

    protected function conversationBetween(User $first, User $second): Conversation
    {
        $conversation = Conversation::create([
            'kind' => ConversationKind::Direct,
            'direct_key' => Conversation::directKey((int) $first->id, (int) $second->id),
            'created_by_id' => $first->id,
        ]);

        $conversation->participants()->attach([$first->id, $second->id]);

        return $conversation;
    }

    /**
     * @param  list<User>  $mates
     */
    protected function groupOf(User $owner, array $mates, string $title = 'Группа'): Conversation
    {
        $group = Conversation::create([
            'kind' => ConversationKind::Group,
            'title' => $title,
            'created_by_id' => $owner->id,
        ]);

        $group->participants()->attach([
            $owner->id,
            ...array_map(static fn (User $one): int => (int) $one->id, $mates),
        ]);

        return $group;
    }

    /**
     * Сказанное в переписке — прямо в базу, минуя отправку.
     *
     * Проверкам отклика, закрепления и поиска нужно не то, как реплика туда
     * попала, а то, что она там есть.
     */
    protected function saidIn(Conversation $conversation, User $author, string $body): Message
    {
        $message = $conversation->messages()->create([
            'user_id' => $author->id,
            'body' => $body,
        ]);

        $conversation->forceFill(['last_message_at' => $message->created_at])->save();

        return $message;
    }
}
