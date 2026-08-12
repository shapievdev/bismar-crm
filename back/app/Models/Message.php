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
#[Fillable(['conversation_id', 'user_id', 'reply_to_id', 'kind', 'body'])]
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

    public function isSystem(): bool
    {
        return $this->kind === MessageKind::System;
    }

    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }
}
