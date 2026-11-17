<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Http\Resources\Lms\CoursePersonResource;
use App\Models\Course;
use App\Models\Department;
use App\Models\Regulation;
use App\Models\User;

final class AccessPickerOptions
{
    public function for(Course|Regulation $material, ?string $jobTitle, string $search): array
    {
        $titles = User::query()->employed()->whereNotNull('job_title')->where('job_title', '<>', '')
            ->distinct()->orderBy('job_title')->pluck('job_title');

        // This is a selection of current employees, not a new permission rule.
        // Do not reuse the 20-row autocomplete limit for a bulk selection.
        $people = $jobTitle === null || $jobTitle === '' ? collect() : User::query()->employed()
            ->where('job_title', $jobTitle)
            ->whereKeyNot($material->author_id ?? 0)
            ->whereNotIn('id', $material->members()->select('users.id'))
            ->when($search !== '', fn ($query) => $query->matching($search))
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')->get();

        $departments = Department::query()->ordered()->get(['id', 'name', 'parent_id']);
        $byId = $departments->keyBy('id');
        $children = $departments->groupBy(fn ($department) => $department->parent_id ?? 0);
        $tree = function (int $parent, int $depth = 0) use (&$tree, $children, $byId): array {
            if ($depth > 64) {
                return [];
            }

            return ($children->get($parent) ?? collect())->map(fn ($department) => [
                'id' => $department->id,
                'name' => $department->name,
                'parent' => $byId->get($department->parent_id)?->name,
                'children' => $tree($department->id, $depth + 1),
            ])->values()->all();
        };

        return ['job_titles' => $titles->all(), 'people' => CoursePersonResource::collection($people)->resolve(), 'departments' => $tree(0)];
    }
}
