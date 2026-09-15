<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отклик на реплику: один знак от одного человека.
 *
 * Своей жизни у него нет — он существует, пока стоит под сообщением, и исчезает
 * вместе с ним. Отсюда и отсутствие мягкого удаления: снятый отклик не надо
 * помнить, в отличие от удалённой реплики, на которую могли отвечать.
 */
#[Fillable(['message_id', 'user_id', 'emoji'])]
class MessageReaction extends Model
{
    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
