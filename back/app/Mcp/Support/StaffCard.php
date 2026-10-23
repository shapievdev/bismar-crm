<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Models\Department;
use App\Models\StaffTag;
use App\Models\User;

/**
 * Сотрудник, рассказанный машине.
 *
 * Не UserResource, хотя соблазн был. Тот отвечает экрану и несёт то, что нужно
 * экрану: подписанную ссылку на аватар (а это обращение к хранилищу на каждую
 * строку списка), метки для подписей, ISO-8601 с часовым поясом. Модели из
 * этого не нужно ничего: ей нужны имя, должность, положение и номера, по
 * которым позвать следующий инструмент, — и чем короче ответ, тем больше его
 * поместится в разговор.
 *
 * Два вида: строка списка и карточка. В списке двадцать пять человек, и
 * расписывать каждого целиком значило бы отдать на список столько же места,
 * сколько на двадцать пять карточек.
 */
final class StaffCard
{
    /**
     * Строка списка.
     *
     * @return array<string, mixed>
     */
    public static function brief(User $user): array
    {
        return array_filter([
            'id' => $user->getKey(),
            'name' => $user->name,
            'job_title' => $user->job_title,
            'phone' => $user->phone,
            'status' => $user->employmentStatus()->value,
            'access_level' => $user->accessLevel()->value,
            'departments' => $user->relationLoaded('departments')
                ? $user->departments->map(static fn (Department $department): string => $department->name)->all()
                : null,
            'dismissed_at' => $user->dismissed_at?->toDateString(),
            'deleted_at' => $user->deleted_at?->toDateString(),
            // Кто удалил — только в списке удалённых, где он и загружен.
            'deleted_by' => $user->relationLoaded('remover') ? $user->remover?->name : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Карточка целиком.
     *
     * Права — действующие, а не отмеченные: у администратора отмечено ничего, а
     * может он всё, и «permissions: []» сказало бы о нём неправду. Отмеченные
     * идут рядом, своим полем: их и правят.
     *
     * @return array<string, mixed>
     */
    public static function full(User $user): array
    {
        $level = $user->accessLevel();

        return array_filter([
            'id' => $user->getKey(),
            'name' => $user->name,
            'last_name' => $user->last_name,
            'first_name' => $user->first_name,
            'middle_name' => $user->middle_name,
            'phone' => $user->phone,
            'email' => $user->email,
            'job_title' => $user->job_title,

            'status' => $user->employmentStatus()->value,
            'status_label' => $user->employmentStatus()->label(),
            'work_mode' => $user->work_mode?->value,
            'hired_at' => $user->hired_at?->toDateString(),
            'tenure_months' => $user->tenureMonths(),
            'dismissed_at' => $user->dismissed_at?->toDateString(),
            'dismissal_reason' => $user->dismissal_reason?->value,
            'dismissal_reason_label' => $user->dismissal_reason?->label(),
            'dismissed_by' => $user->relationLoaded('dismissedBy') ? $user->dismissedBy?->name : null,
            'deleted_at' => $user->deleted_at?->toDateString(),

            'access_level' => $level->value,
            'access_level_label' => $level->label(),
            'grants_everything' => $level->grantsEverything(),
            'own_permissions' => $user->permissions->pluck('name')->sort()->values()->all(),

            'mentor' => $user->mentor === null ? null : [
                'id' => $user->mentor->getKey(),
                'name' => $user->mentor->name,
            ],

            'departments' => $user->departments
                ->map(static fn (Department $department): array => [
                    'id' => $department->getKey(),
                    'name' => $department->name,
                    'role' => $department->pivot?->role,
                ])->all(),

            'tags' => $user->staffTags
                ->map(static fn (StaffTag $tag): array => [
                    'id' => $tag->getKey(),
                    'name' => $tag->name,
                ])->all(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
