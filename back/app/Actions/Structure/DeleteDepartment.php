<?php

declare(strict_types=1);

namespace App\Actions\Structure;

use App\Exceptions\ConflictException;
use App\Models\Department;
use App\Support\Structure\SiblingPositions;
use Illuminate\Support\Facades\DB;

/**
 * Удаление отдела.
 *
 * Подчинённые отделы не уходят вместе с ним, а поднимаются к деду:
 * расформированное направление обычно означает, что его отделы переподчинили
 * выше, а не что их распустили. Люди же теряют только эту приписку — сами записи
 * сотрудников не трогаются, они живут своей жизнью.
 */
final readonly class DeleteDepartment
{
    public function __construct(private SiblingPositions $positions) {}

    /**
     * @throws ConflictException
     */
    public function handle(Department $department): void
    {
        if ($department->isRoot()) {
            throw new ConflictException('Компанию удалить нельзя — это вершина структуры.');
        }

        $parentId = $department->parent_id;

        DB::transaction(function () use ($department, $parentId): void {
            Department::query()
                ->where('parent_id', $department->getKey())
                ->update(['parent_id' => $parentId]);

            // Строки в department_members уходят каскадом: приписка к отделу,
            // которого больше нет, ничего не значит.
            $department->delete();

            $this->positions->renumber($parentId);
        });
    }
}
