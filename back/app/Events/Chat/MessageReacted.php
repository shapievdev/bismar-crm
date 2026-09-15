<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Support\Chat\ReactionSummary;

/**
 * Под репликой откликнулись — или сняли отклик.
 *
 * Уходит весь набор откликов, а не одна перемена. Перемен бывает несколько
 * подряд, приходят они не в том порядке, в каком случились, и вкладке,
 * складывающей их по одной, ничего не стоит разойтись с базой. Набор целиком
 * всегда прав, а весит он десятки байт.
 */
final class MessageReacted extends MessageEvent
{
    public function broadcastAs(): string
    {
        return 'message.reacted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->getKey(),
            'reactions' => ReactionSummary::of($this->message->loadMissing('reactions.person')),
        ];
    }
}
