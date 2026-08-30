<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\AccessLevel;
use App\Exceptions\ConflictException;
use App\Models\User;
use App\Support\Lms\StoredFiles;
use Illuminate\Support\Facades\DB;

/**
 * Удаление учётной записи насовсем.
 *
 * Крайняя мера, а не будничная: обыкновенный уход из компании — это увольнение
 * (ChangeEmployment), после которого человек остаётся подписан под тем, что
 * написал. Удаляют, когда записи не должно остаться вовсе, — заведённой по
 * ошибке или той, что просят стереть.
 *
 * Что уходит вместе с записью, решают внешние ключи, расставленные при
 * создании таблиц, а не этот класс: прогресс, попытки тестов, план обучения,
 * допуски и ответственность за курсы уходят следом (cascade), а авторство
 * курсов и новостей, сообщения в переписках и вопросы к консультанту остаются
 * без имени (null). Читателю уже написанное важнее, чем подпись под ним, и
 * стирать чужие разговоры вместе с человеком было бы куда разрушительнее.
 */
final readonly class DeleteUser
{
    private const AVATAR_DISK = 's3';

    /**
     * @throws ConflictException
     */
    public function handle(User $user, User $actor): void
    {
        $this->ensureMayBeDeleted($user, $actor);

        $avatar = $user->avatar_path;

        DB::transaction(function () use ($user): void {
            // Сессии на учётную запись ключом не завязаны — иначе строка
            // осталась бы висеть с номером, за которым уже никого нет.
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->getKey())
                    ->delete();
            }

            $user->delete();
        });

        // Файл — уже после того, как запись удалилась: неудача в хранилище не
        // должна отменять удаление, см. StoredFiles.
        StoredFiles::discard(self::AVATAR_DISK, $avatar);
    }

    /**
     * Кого и кому позволено удалять.
     *
     * Только суперадминистратору и только уволенного: увольнение — обязательный
     * первый шаг, и не ради обряда. Оно обрывает сессии, закрывает вход и даёт
     * время передумать; удаление же не отменить ничем.
     *
     * Проверка живёт здесь, а не в политике: Gate::before пропускает
     * администраторов не глядя, и политика бы не спросилась.
     *
     * @throws ConflictException
     */
    private function ensureMayBeDeleted(User $user, User $actor): void
    {
        if ($actor->accessLevel() !== AccessLevel::SuperAdmin) {
            throw new ConflictException('Удалять учётные записи может только суперадминистратор.');
        }

        if ($user->is($actor)) {
            throw new ConflictException('Нельзя удалить самого себя.');
        }

        if (! $user->isDismissed()) {
            throw new ConflictException('Удалить можно только уволенного сотрудника — сначала увольте его.');
        }
    }
}
