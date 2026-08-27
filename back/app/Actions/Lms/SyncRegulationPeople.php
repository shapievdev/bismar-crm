<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Regulation;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Списки людей у регламента: допущенные и ответственные.
 *
 * Одно действие на оба, в отличие от курсов, где под них два класса: разница
 * между ними только в имени колонки «кто назначил», а рассуждение — общее.
 *
 * Список задаётся целиком, а не по одному человеку: экран показывает его весь,
 * и «сохранить» там означает «пусть будет вот так». Разница видна, когда двое
 * правят список одновременно, — но здесь это правильнее: увидеть в закрытом
 * регламенте человека, которого ты только что убрал, хуже, чем потерять чужое
 * добавление, о котором на экране и не говорилось.
 */
final readonly class SyncRegulationPeople
{
    /**
     * @param  list<int>  $userIds
     */
    public function admit(Regulation $regulation, array $userIds, User $actor): Regulation
    {
        // Автор в списке не состоит: его доступ следует из авторства, и строка
        // о нём означала бы, что доступ можно снять, — а его нельзя.
        return $this->sync(
            $regulation,
            $regulation->members(),
            array_values(array_diff($this->clean($userIds), [$regulation->author_id])),
            'granted_by_id',
            $actor,
            'members',
        );
    }

    /**
     * @param  list<int>  $userIds
     */
    public function appoint(Regulation $regulation, array $userIds, User $actor): Regulation
    {
        // Автор ответственным быть может: собрал правило — с него и спросят.
        return $this->sync(
            $regulation,
            $regulation->experts(),
            $this->clean($userIds),
            'appointed_by_id',
            $actor,
            'experts',
        );
    }

    /**
     * @param  BelongsToMany<User, Regulation>  $relation
     * @param  list<int>  $wanted
     */
    private function sync(
        Regulation $regulation,
        BelongsToMany $relation,
        array $wanted,
        string $byColumn,
        User $actor,
        string $reload,
    ): Regulation {
        DB::transaction(function () use ($relation, $wanted, $byColumn, $actor): void {
            $current = $relation->pluck('users.id')->map(intval(...))->all();

            $relation->detach(array_values(array_diff($current, $wanted)));

            $added = array_values(array_diff($wanted, $current));

            if ($added !== []) {
                // Пропущенным через attach, а не sync: sync переписал бы «кто
                // это сделал» у тех, кого добавили до этого.
                $relation->attach($added, [$byColumn => $actor->getKey()]);
            }
        });

        return $regulation->load($reload);
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function clean(array $userIds): array
    {
        return array_values(array_unique(array_map(intval(...), $userIds)));
    }
}
