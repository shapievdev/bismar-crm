<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Переписка в списке и в заголовке ленты.
 *
 * Имя у личной переписки не своё, а собеседника, и потому считается на лету:
 * храни его — и оно разойдётся с настоящим в тот день, когда человек сменит
 * фамилию.
 *
 * @mixin Conversation
 */
final class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $reader */
        $reader = $request->user();

        $companion = $this->companionFor($reader);

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'is_group' => $this->isGroup(),

            'title' => $this->isGroup()
                ? (string) $this->title
                : ($companion?->name ?? 'Переписка'),

            // Собеседник личной переписки: его лицо стоит в списке, его же
            // «в сети» показывают в заголовке.
            'companion' => $companion === null ? null : PersonResource::make($companion)->resolve(),

            'participants' => PersonResource::collection($this->whenLoaded('activeParticipants')),
            'participants_count' => $this->whenCounted('activeParticipants'),

            'last_message' => MessageResource::make($this->whenLoaded('lastMessage')),
            'last_message_at' => $this->last_message_at?->toIso8601String(),

            // Сколько сообщений человек ещё не прочёл. Считается одним запросом
            // на весь список — см. App\Support\Chat\Unread.
            'unread_count' => (int) ($this->unread_count ?? 0),

            // Группу переименовывает и правит состав тот, кто её завёл.
            'is_owner' => $reader !== null && $this->created_by_id === $reader->getKey(),
        ];
    }

    /**
     * Собеседник — тот участник личной переписки, который не читатель.
     */
    private function companionFor(?User $reader): ?User
    {
        if ($this->isGroup() || $reader === null || ! $this->relationLoaded('activeParticipants')) {
            return null;
        }

        return $this->activeParticipants->firstWhere(
            fn (User $person): bool => $person->getKey() !== $reader->getKey(),
        );
    }
}
