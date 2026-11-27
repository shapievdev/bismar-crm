<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Structure\DepartmentReach;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Current admission, independently of enrollment or past activity. */
final class MaterialAudience
{
    /** @return Collection<int, User> */
    public function people(Course|Regulation $material): Collection
    {
        $course = $material instanceof Course;
        $prefix = $course ? 'course' : 'regulation';
        $permission = $course
            ? ($material->status->isOpenToLearners() ? Permission::ViewCourses : Permission::UpdateCourses)
            : ($material->isPublished() ? $material->kind->viewPermission() : $material->kind->updatePermission());
        $administrators = [AccessLevel::Admin->value, AccessLevel::SuperAdmin->value];

        $query = User::query()->employed()->where(function (Builder $query) use ($permission, $administrators): void {
            $query->permission($permission->value)
                ->orWhereHas('roles', fn (Builder $roles) => $roles->whereIn('name', $administrators));
        });

        $private = $course ? $material->visibility->isPrivate() : $material->isPrivate();
        $departments = app(DepartmentReach::class)->branch($material->memberDepartments()->pluck('departments.id')->map(intval(...))->all());
        // Administrative visibility does not constitute an assignment to learn.
        // Administrators enter the audience only through an explicit admission.
        $query->where(function (Builder $query) use ($private, $material, $prefix, $departments, $administrators): void {
            $query->where(function (Builder $ordinary) use ($private, $material, $administrators): void {
                $ordinary->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', $administrators));
                if ($private) {
                    $ordinary->where('users.id', $material->author_id);
                }
            })->orWhere(function (Builder $assigned) use ($material, $prefix, $departments): void {
                $assigned->whereIn('users.id', DB::table($prefix.'_members')->where($prefix.'_id', $material->id)->select('user_id'))
                    ->orWhereIn('users.id', DB::table($prefix.'_member_groups')
                        ->join('group_members', 'group_members.group_id', '=', $prefix.'_member_groups.group_id')
                        ->where($prefix.'_member_groups.'.$prefix.'_id', $material->id)->select('group_members.user_id'))
                    ->orWhereHas('departments', fn (Builder $memberships) => $memberships->whereIn('departments.id', $departments));
            });
        });

        return $query->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')->orderBy('users.id')->get();
    }
}
