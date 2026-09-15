<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Одно сообщение в переписке.
 *
 * @property-read MessageKind $kind
 */
#[Fillable(['conversation_id', 'user_id', 'reply_to_id', 'kind', 'body', 'about', 'forwarded', 'mentions'])]
class Message extends Model
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MessageKind::class,
            'edited_at' => 'datetime',
            'pinned_at' => 'datetime',

            // Материал, с которого написали, — снимком на день отправки.
            'about' => 'array',

            // Откуда переслано — тоже снимком, и по той же причине: исходной
            // реплики может уже не быть.
            'forwarded' => 'array',

            // Кого позвали: список номеров сотрудников.
            'mentions' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Автор, или никто — если он с тех пор уволился.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Реплика, на которую отвечали.
     *
     * Вместе с удалёнными: ответ на удалённое должен показывать «сообщение
     * удалено», а не молча терять цитату — иначе «да, согласен» повисает без
     * того, с чем соглашались.
     *
     * @return BelongsTo<Message, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id')->withTrashed();
    }

    /**
     * @return HasMany<MessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    /**
     * Отклики: кто и каким знаком ответил, не занимая ленту.
     *
     * @return HasMany<MessageReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * Кто закрепил. Уволился — закрепление остаётся: оно про реплику, а не про
     * того, кто поднял её наверх.
     *
     * @return BelongsTo<User, $this>
     */
    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_id');
    }

    public function isSystem(): bool
    {
        return $this->kind === MessageKind::System;
    }

    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    /**
     * Позвали ли этим сообщением вот этого человека.
     *
     * Номера приводятся к числам перед сравнением: в jsonb они могли осесть
     * строками — так их присылает форма, — и строгое сравнение промолчало бы,
     * то есть упоминание не дошло бы до того, кого звали.
     */
    public function mentions(User $person): bool
    {
        $called = $this->mentions;

        return is_array($called) && in_array($person->getKey(), array_map(intval(...), $called), strict: true);
    }
}
