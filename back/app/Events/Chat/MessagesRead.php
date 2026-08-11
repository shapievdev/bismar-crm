<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Собеседник дочитал до этого места.
 *
 * Уходит только в канал самой переписки: галочки о прочтении интересны тому,
 * кто в неё смотрит, а списку переписок от них ничего не меняется.
 */
final class MessagesRead implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly User $reader,
        public readonly string $readAt,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversations.'.$this->conversation->getKey())];
    }

    public function broadcastAs(): string
    {
        return 'messages.read';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->getKey(),
            'user_id' => $this->reader->getKey(),
            'read_at' => $this->readAt,
        ];
    }
}
