<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Убирает разом несколько реплик — то, что выделили в ленте.
 *
 * Права проверяются у всех до того, как удалена первая. Иначе выделение из
 * десяти сообщений, среди которых одно чужое, оставляло бы девять удалёнными и
 * сообщение об ошибке на десятом — а вернуть удалённое нельзя.
 *
 * Само удаление идёт по одному, тем же действием, что и поштучное: у него на
 * руках вложения, хранилище и рассылка события, и повторять всё это второй раз
 * значит завести второе место, где удаление может разойтись с первым.
 */
final readonly class DeleteMessages
{
    public function __construct(private DeleteMessage $delete) {}

    /**
     * @param  Collection<int, Message>  $messages
     * @return int сколько убрано
     *
     * @throws AuthorizationException если хоть одно из них удалять нельзя
     */
    public function handle(Collection $messages, User $actor): int
    {
        foreach ($messages as $message) {
            if ($actor->cannot('delete', $message)) {
                throw new AuthorizationException('Среди выбранного есть сообщение, которое удалить нельзя.');
            }
        }

        foreach ($messages as $message) {
            $this->delete->handle($message);
        }

        return $messages->count();
    }
}
