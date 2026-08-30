<?php

declare(strict_types=1);

namespace App\Support\Structure;

use App\Models\Department;

/**
 * Порядок отделов среди соседей.
 *
 * Номера идут подряд с нуля и переписываются после каждой перестановки: с
 * дырами в нумерации «встать третьим» пришлось бы вычислять, а не назначать, и
 * два перетаскивания подряд разошлись бы с тем, что видит человек.
 */
final class SiblingPositions
{
    /**
     * Пересчитывает номера детей одного родителя, сохраняя нынешний порядок.
     */
    public function renumber(?int $parentId): void
    {
        Department::query()
            ->where('parent_id', $parentId)
            ->ordered()
            ->get()
            ->each(function (Department $sibling, int $index): void {
                if ($sibling->position !== $index) {
                    $sibling->update(['position' => $index]);
                }
            });
    }

    /**
     * Ставит отдел на указанное место среди детей родителя.
     *
     * Место считается по списку без самого переносимого: перетаскивая карточку
     * вниз в пределах одного родителя, человек указывает промежуток в том
     * списке, который видит, — а видит он его уже без неё.
     */
    public function place(Department $department, ?int $parentId, int $position): void
    {
        /** @var list<int> $order */
        $order = Department::query()
            ->where('parent_id', $parentId)
            ->whereKeyNot($department->getKey())
            ->ordered()
            ->pluck('id')
            ->map(intval(...))
            ->all();

        $at = max(0, min($position, count($order)));
        array_splice($order, $at, 0, [(int) $department->getKey()]);

        $department->update(['parent_id' => $parentId, 'position' => $at]);

        foreach ($order as $index => $id) {
            Department::query()->whereKey($id)->update(['position' => $index]);
        }
    }
}
