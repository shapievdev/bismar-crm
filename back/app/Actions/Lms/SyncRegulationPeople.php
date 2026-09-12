<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Regulation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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
     * @param  list<int>  $groupIds  группы, впущенные целиком (2026-09-12)
     */
    public function admit(Regulation $regulation, array $userIds, array $groupIds, User $actor): Regulation
    {
        DB::transaction(function () use ($regulation, $userIds, $groupIds, $actor): void {
            // Автор в списке не состоит: его доступ следует из авторства, и
            // строка о нём означала бы, что доступ можно снять, — а его нельзя.
            $this->apply(
                $regulation->members(),
                array_values(array_diff($this->clean($userIds), [$regulation->author_id])),
                'users.id',
                'granted_by_id',
                $actor,
            );

            $this->apply(
                $regulation->memberGroups(),
                $this->clean($groupIds),
                'groups.id',
                'granted_by_id',
                $actor,
            );
        });

        return $regulation->load('members', 'memberGroups');
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
            $this->apply($relation, $wanted, 'users.id', $byColumn, $actor);
        });

        return $regulation->load($reload);
    }

    /**
     * Привести список к присланному.
     *
     * Один способ на людей и на группы: правило у них общее — лишних убрать,
     * новых добавить, уже добавленных не трогать.
     *
     * @param  BelongsToMany<Model, Regulation>  $relation
     * @param  list<int>  $wanted
     */
    private function apply(
        BelongsToMany $relation,
        array $wanted,
        string $key,
        string $byColumn,
        User $actor,
    ): void {
        $current = $relation->pluck($key)->map(intval(...))->all();

        $relation->detach(array_values(array_diff($current, $wanted)));

        $added = array_values(array_diff($wanted, $current));

        if ($added !== []) {
            // Пропущенным через attach, а не sync: sync переписал бы «кто
            // это сделал» у тех, кого добавили до этого.
            $relation->attach($added, [$byColumn => $actor->getKey()]);
        }
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
