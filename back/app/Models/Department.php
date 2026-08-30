<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DepartmentRole;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Отдел — узел структуры компании.
 *
 * Корень (компания целиком) — единственный отдел без родителя; его не удаляют
 * и не подчиняют никому. Остальные всегда стоят под кем-то.
 */
#[Fillable(['name', 'parent_id', 'position'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Department, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Department, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->ordered();
    }

    /**
     * Люди отдела — с ролью, в которой они здесь числятся.
     *
     * Уволенные отсеиваются: структура отвечает на вопрос «кто сейчас в
     * отделе», и ушедший в ней только сбивал бы счёт. Строка связи остаётся —
     * вернувшийся в строй встаёт на своё прежнее место.
     *
     * @return BelongsToMany<User, $this>
     */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_members')
            ->withPivot('role')
            ->withTimestamps()
            // Столбец назван таблицей: в запросе к связи их две, и без имени
            // Postgres не знает, чьё это поле.
            ->whereNull('users.dismissed_at');
    }

    /**
     * Люди отдела в одной роли.
     *
     * @return BelongsToMany<User, $this>
     */
    public function peopleAs(DepartmentRole $role): BelongsToMany
    {
        return $this->people()->wherePivot('role', $role->value);
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Этот отдел и всё, что под ним.
     *
     * Считается по уже загруженному дереву — отношение `children` к этому
     * времени наполнено, — поэтому ходит по памяти, а не в базу за каждым
     * узлом.
     *
     * @return list<int>
     */
    public function branchIds(): array
    {
        $ids = [(int) $this->getKey()];

        /** @var Collection<int, Department> $children */
        $children = $this->children;

        foreach ($children as $child) {
            $ids = [...$ids, ...$child->branchIds()];
        }

        return $ids;
    }

    /**
     * Не замкнётся ли дерево, если подчинить этот отдел кандидату — самому
     * себе или собственному потомку.
     */
    public function wouldCycleUnder(?int $candidateParentId): bool
    {
        if ($candidateParentId === null) {
            return false;
        }

        return in_array($candidateParentId, $this->branchIds(), strict: true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }
}
