<?php

declare(strict_types=1);

namespace App\Actions\Structure;

use App\Exceptions\ConflictException;
use App\Models\Department;
use App\Support\Structure\SiblingPositions;
use Illuminate\Support\Facades\DB;

/**
 * Перенос отдела: под другого родителя или на другое место среди соседей.
 *
 * Это то, что происходит при перетаскивании карточки, и потому правила здесь —
 * не формальность: бросить отдел на собственного потомка легко, а дерево
 * после этого замыкается в кольцо и не рисуется вовсе.
 */
final readonly class MoveDepartment
{
    public function __construct(private SiblingPositions $positions) {}

    /**
     * @throws ConflictException
     */
    public function handle(Department $department, int $parentId, int $position): Department
    {
        if ($department->isRoot()) {
            throw new ConflictException('Компанию нельзя подчинить отделу — это вершина структуры.');
        }

        $parent = Department::query()->find($parentId);

        if ($parent === null) {
            throw new ConflictException('Отдел, которому подчиняют, не найден.');
        }

        if ($this->wouldCycle($department, $parentId)) {
            throw new ConflictException('Отдел нельзя подчинить самому себе или своему же подразделению.');
        }

        DB::transaction(function () use ($department, $parentId, $position): void {
            $previousParent = $department->parent_id;

            $this->positions->place($department, $parentId, $position);

            // У прежнего родителя после ухода ребёнка остаётся дыра в
            // нумерации: следующий перенос считал бы места по ней.
            if ($previousParent !== $parentId) {
                $this->positions->renumber($previousParent);
            }
        });

        return $department->refresh();
    }

    /**
     * Замкнётся ли дерево. Считается по одному запросу за весь справочник:
     * отделов десятки, и подниматься от кандидата к корню по одному — лишние
     * походы в базу ради того же ответа.
     */
    private function wouldCycle(Department $department, int $parentId): bool
    {
        if ((int) $department->getKey() === $parentId) {
            return true;
        }

        /** @var array<int, int|null> $parents */
        $parents = Department::query()->pluck('parent_id', 'id')->all();

        $ancestor = $parents[$parentId] ?? null;

        while ($ancestor !== null) {
            if ($ancestor === (int) $department->getKey()) {
                return true;
            }

            $ancestor = $parents[$ancestor] ?? null;
        }

        return false;
    }
}
