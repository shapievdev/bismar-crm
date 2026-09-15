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

            // Не колонки этой таблицы, а отметки читателя, приставленные
            // подзапросами, — см. scopeWithReaderMarks().
            'cleared_at' => 'datetime',
            'muted_at' => 'datetime',
            'pinned_at' => 'datetime',
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
            ->withPivot(['last_read_at', 'left_at', 'cleared_at', 'muted_at', 'pinned_at'])
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
     * Строки участия — те же, что за `participants()`, но сами по себе.
     *
     * Нужны там, где интересен не человек, а его отметки: до какого места
     * дочитал, вышел ли, когда удалил переписку у себя.
     *
     * @return HasMany<ConversationParticipant, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function membershipOf(User $user): ?ConversationParticipant
    {
        return $this->memberships()->where('user_id', $user->getKey())->first();
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
     * Переписки этого человека — те, из которых он не вышел и которые не удалил
     * у себя.
     *
     * Удалённая у себя возвращается, как только в ней снова что-то скажут:
     * сравнение идёт с временем последнего сообщения, и потому обходится
     * колонкой самой переписки — она же и индексирована — вместо подзапроса к
     * ленте на каждую строчку списка.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOf(Builder $query, User $user): void
    {
        $query->whereHas(
            'participants',
            fn (Builder $participants) => $participants
                ->whereKey($user->getKey())
                ->whereNull('conversation_participants.left_at')
                ->where(fn (Builder $cleared) => $cleared
                    ->whereNull('conversation_participants.cleared_at')
                    ->orWhereColumn('conversations.last_message_at', '>', 'conversation_participants.cleared_at')),
        );
    }

    /**
     * Приставляет к переписке личные отметки читателя: когда он удалил её у
     * себя, приглушил и поднял наверх списка.
     *
     * Подзапросами на весь список, а не обращением на строчку: список переписок
     * открывают в мессенджере чаще всего. Строка участия у пары «переписка и
     * человек» одна, поэтому три подзапроса читают одну и ту же строку и
     * обходятся Postgres в один проход по уникальному ключу.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWithReaderMarks(Builder $query, User $reader): void
    {
        foreach (['cleared_at', 'muted_at', 'pinned_at'] as $mark) {
            $query->addSelect([$mark => ConversationParticipant::query()
                ->select($mark)
                ->whereColumn('conversation_participants.conversation_id', 'conversations.id')
                ->where('conversation_participants.user_id', $reader->getKey())
                ->limit(1),
            ]);
        }
    }

    /**
     * Закреплённые реплики — последняя закреплённая первой.
     *
     * Удалённые сюда не попадают: закрепление переживает правку, но не удаление
     * — на полосе наверху нечего было бы показать.
     *
     * @return HasMany<Message, $this>
     */
    public function pinnedMessages(): HasMany
    {
        return $this->messages()
            ->whereNotNull('pinned_at')
            ->orderByDesc('pinned_at');
    }
}
