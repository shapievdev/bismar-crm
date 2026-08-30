<?php

declare(strict_types=1);

namespace App\Support\Structure;

use App\Enums\DepartmentRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Дерево компании, собранное разом.
 *
 * Отделов в компании десятки, а не тысячи, поэтому дешевле взять их одним
 * запросом и сложить дерево в памяти, чем ходить в базу за каждым уровнем.
 * Тем же проходом считаются числа, которые показывает карточка:
 *
 *  - «Подчинённые: N сотрудников» — прямые участники отдела;
 *  - число рядом с именем руководителя — сколько людей во всём его кусте,
 *    считая вложенные отделы (человека, числящегося в двух отделах куста,
 *    считаем один раз — иначе сумма по компании обгоняет штат);
 *  - «N отделов» — сколько отделов подчинено прямо.
 */
final class CompanyTree
{
    /**
     * Корни с проставленными детьми и счётчиками.
     *
     * @return Collection<int, Department>
     */
    public function build(): Collection
    {
        $departments = Department::query()
            ->with(['people' => fn ($query) => $query->orderByRaw(
                'COALESCE(users.last_name, users.first_name) COLLATE "und-x-icu"',
            )])
            ->ordered()
            ->get();

        $byParent = $departments->groupBy('parent_id');

        /** @var Collection<int, Department> $roots */
        $roots = $departments->whereNull('parent_id')->values();

        foreach ($roots as $root) {
            $this->attach($root, $byParent);
        }

        return $roots;
    }

    /**
     * Один отдел с детьми и счётчиками — для ответа на правку, когда всё
     * дерево пересылать незачем.
     */
    public function node(Department $department): Department
    {
        $found = $this->build()->pipe(fn (Collection $roots) => $this->find($roots, (int) $department->getKey()));

        return $found ?? $department;
    }

    /**
     * Раскладывает детей по узлу и считает его числа снизу вверх.
     *
     * @param  \Illuminate\Support\Collection<int|string|null, Collection<int, Department>>  $byParent
     * @return list<int> идентификаторы людей всего куста — родителю, чтобы сложить свой
     */
    private function attach(Department $node, $byParent): array
    {
        /** @var Collection<int, Department> $children */
        $children = $byParent->get($node->getKey(), new Collection);

        $node->setRelation('children', $children);

        /** @var Collection<int, User> $people */
        $people = $node->people;

        $branch = $people->pluck('id')->map(intval(...))->all();

        foreach ($children as $child) {
            $branch = [...$branch, ...$this->attach($child, $byParent)];
        }

        // Люди в кусте — по одному разу: человек нередко числится и в шапке
        // направления, и в отделе под ним.
        $node->setAttribute('people_total', count(array_unique($branch)));
        $node->setAttribute('members_count', $this->countIn($people, DepartmentRole::Member));
        $node->setAttribute('children_count', $children->count());

        return $branch;
    }

    /**
     * @param  Collection<int, User>  $people
     */
    private function countIn(Collection $people, DepartmentRole $role): int
    {
        return $people->filter(
            static fn (User $person): bool => $person->getAttribute('pivot')->role === $role->value,
        )->count();
    }

    /**
     * @param  Collection<int, Department>  $nodes
     */
    private function find(Collection $nodes, int $id): ?Department
    {
        foreach ($nodes as $node) {
            if ((int) $node->getKey() === $id) {
                return $node;
            }

            /** @var Collection<int, Department> $children */
            $children = $node->children;
            $found = $this->find($children, $id);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
