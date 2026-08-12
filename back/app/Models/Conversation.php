<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversationKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Переписка: личная на двоих или групповая.
 *
 * @property-read ConversationKind $kind
 */
#[Fillable(['kind', 'title', 'created_by_id', 'direct_key', 'last_message_at'])]
class Conversation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ConversationKind::class,
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * Пара людей, свёрнутая в строку, — по ней личная переписка и находится.
     *
     * Меньший номер первым: «кто кому написал первым» не должно рождать две
     * разные переписки для одной и той же пары.
     */
    public static function directKey(int $first, int $second): string
    {
        $pair = [$first, $second];
        sort($pair);

        return implode(':', $pair);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->using(ConversationParticipant::class)
            ->withPivot(['last_read_at', 'left_at'])
            ->withTimestamps();
    }

    /**
     * Те, кто в переписке сейчас: вышедшие остаются строкой ради своих
     * прошлых сообщений, но новых не получают и в составе не числятся.
     *
     * @return BelongsToMany<User, $this>
     */
    public function activeParticipants(): BelongsToMany
    {
        return $this->participants()->wherePivotNull('left_at');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Последнее сообщение — для списка переписок.
     *
     * Удалённые исключаются здесь же, внутри подзапроса, а не полагаясь на
     * общее правило мягкого удаления: `latestOfMany` сначала выбирает
     * наибольший идентификатор отдельным запросом, и правило до него не
     * доходит. Без этого удаление последней реплики оставляло её же в строчке
     * списка — пустую, потому что текст при удалении обнуляется.
     *
     * @return HasOne<Message, $this>
     */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->whereNull('deleted_at'),
        );
    }

    public function isGroup(): bool
    {
        return $this->kind->isGroup();
    }

    /** Состоит ли человек в переписке сейчас. */
    public function includes(User $user): bool
    {
        return $this->activeParticipants()->whereKey($user->getKey())->exists();
    }

    /**
     * Переписки этого человека — те, из которых он не вышел.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOf(Builder $query, User $user): void
    {
        $query->whereHas(
            'participants',
            fn (Builder $participants) => $participants
                ->whereKey($user->getKey())
                ->whereNull('conversation_participants.left_at'),
        );
    }
}
