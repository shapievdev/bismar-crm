<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Events\Chat\MessageEdited;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Переписывает уже сказанное — и только своё.
 *
 * Авторство проверяется здесь, а не одной лишь политикой, и это не
 * перестраховка: `Gate::before` пропускает администраторов мимо любой политики
 * (см. AppServiceProvider), а подпись под сообщением утверждает, что сказал это
 * именно тот, кто под ним значится. Право администратора переписать чужие слова
 * от чужого имени сделало бы подпись ложью, поэтому запрет живёт там, где его
 * никто не обойдёт.
 */
final readonly class EditMessage
{
    /**
     * @throws AuthorizationException если правят чужое, системное или удалённое
     */
    public function handle(Message $message, User $editor, ?string $body): Message
    {
        if ($message->user_id !== $editor->getKey()) {
            throw new AuthorizationException('Править можно только собственные сообщения.');
        }

        if ($message->isSystem() || $message->trashed()) {
            throw new AuthorizationException('Это сообщение изменить нельзя.');
        }

        $body = $body === null ? null : trim($body);
        $body = $body === '' ? null : $body;

        // Реплика не может остаться совсем пустой: без текста и без вложений
        // на её месте была бы дыра, а это уже удаление — и делается оно иначе.
        if ($body === null && $message->attachments()->doesntExist()) {
            throw new AuthorizationException('Сообщение без текста и вложений — это удаление.');
        }

        DB::transaction(function () use ($message, $body): void {
            $message->forceFill([
                'body' => $body,
                'edited_at' => now(),
            ])->save();
        });

        MessageEdited::dispatch($message->load(['author', 'attachments', 'replyTo.author']));

        return $message;
    }
}