<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\AccessLevel;
use App\Exceptions\ConflictException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Возвращение удалённой записи.
 *
 * Обратная сторона DeleteUser, и ради неё удаление и сделали мягким: удалить
 * не того человека так же просто, как уволить не того, а исправлять это
 * запросом в базу — не исправление.
 *
 * Запись возвращается уволенной — той, какой её удаляли. Это не оговорка: удалять
 * позволено только уволенного, и возвращение в строй — отдельное решение,
 * которое принимают отдельно (ChangeEmployment). Уровень доступа и отмеченные
 * права при этом никуда не девались: удаление их не снимало.
 *
 * Отдельным классом, а не вторым действием DeleteUser: удаление и возвращение
 * сдерживаются разными условиями — у первого их три, у второго одно, — и общего
 * у них ровно столько, сколько видно выше.
 */
final readonly class RestoreUser
{
    /**
     * @throws ConflictException
     */
    public function handle(User $user, User $actor): User
    {
        $this->ensureMayBeRestored($user, $actor);

        DB::transaction(function () use ($user): void {
            $this->releaseContactsTakenMeanwhile($user);

            // Запись об удалении уходит вместе с удалением: возвращённая
            // запись — обыкновенная, а не «удалённая, которую достали».
            $user->forceFill(['deleted_by_id' => null]);

            $user->restore();
        });

        return $user->load('roles', 'permissions');
    }

    /**
     * Кому позволено возвращать: тому же, кто удаляет, — суперадминистратору.
     * Кто вправе убрать, тот вправе и достать обратно; так же устроена корзина
     * базы знаний.
     *
     * Проверка живёт здесь, а не в политике, по той же причине, что и в
     * DeleteUser: Gate::before пропускает администраторов не глядя.
     *
     * @throws ConflictException
     */
    private function ensureMayBeRestored(User $user, User $actor): void
    {
        if ($actor->accessLevel() !== AccessLevel::SuperAdmin) {
            throw new ConflictException('Возвращать удалённые учётные записи может только суперадминистратор.');
        }

        if (! $user->trashed()) {
            throw new ConflictException('Эта учётная запись не удалена.');
        }
    }

    /**
     * Снимает с возвращаемой записи номер и адрес, уже выданные живому.
     *
     * Удаление освобождает телефон и почту — уникальность в базе спрашивается у
     * живых записей, — и за время, пока запись лежала удалённой, её номер могли
     * выдать новому сотруднику. Тогда возвращать его этой записи нельзя: по
     * номеру входят, и указывать он обязан на одного.
     *
     * Освобождается именно возвращаемая запись, а не живая: с живого номера
     * входят сегодня, а этот остался в прошлом вместе с записью, и нужна она
     * теперь ради истории, а не ради входа. Пустой номер заполнит
     * администратор, если дело дойдёт до возвращения человека в строй.
     */
    private function releaseContactsTakenMeanwhile(User $user): void
    {
        foreach (['phone', 'email'] as $column) {
            $value = $user->getAttribute($column);

            if ($value === null) {
                continue;
            }

            $taken = User::query()
                ->where($column, $value)
                ->whereKeyNot($user->getKey())
                ->exists();

            if ($taken) {
                $user->forceFill([$column => null]);
            }
        }
    }
}
