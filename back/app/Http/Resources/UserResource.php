<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\DepartmentRole;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\StaffTag;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // The joined form for anywhere a name is shown, the parts for the
            // profile form that edits them.
            'name' => $this->name,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,

            'email' => $this->email,

            // Необязательные: незаполненное приходит как null, и экран рисует
            // строку без него, а не пустое место с прочерком.
            'phone' => $this->phone,
            'job_title' => $this->job_title,

            'avatar_url' => $this->avatarUrl(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // Где человек в структуре компании — в карточке и в списке
            // сотрудников, где это отдельный столбец. Приходит, когда отделы
            // подгружены: остальным экранам, зовущим людей в переписку или в
            // план обучения, знать их незачем.
            'departments' => $this->whenLoaded('departments', fn (): array => $this->departments
                ->map(fn (Department $department): array => [
                    'id' => $department->getKey(),
                    'name' => $department->name,
                    'role' => $department->pivot->role,
                    'role_label' => DepartmentRole::from((string) $department->pivot->role)->label(),
                ])->all()),

            /*
             * Кадровое: когда принят, в каком положении числится, как работает
             * и кто его ведёт.
             *
             * Даты приёма может не быть: в базе есть люди, заведённые до того,
             * как её начали спрашивать. Пустое приходит пустым, а не нулём, —
             * отчёт о движении персонала обязан отличать «принят сегодня» от
             * «неизвестно когда».
             */
            'hired_at' => $this->hired_at?->toDateString(),
            'status' => $this->employmentStatus()->value,
            'status_label' => $this->employmentStatus()->label(),
            'work_mode' => $this->work_mode?->value,
            'work_mode_label' => $this->work_mode?->label(),
            'tenure_months' => $this->tenureMonths(),

            /*
             * Ручные теги: «Кадровый резерв», «Испытательный продлён».
             *
             * Только те, что повесил человек. Теги по стажу сюда не попадают и
             * попасть не могут — они считаются из даты приёма и живут в отчёте,
             * а не в карточке: смешай их здесь, и кадровик однажды попробовал
             * бы снять «старожила» руками.
             */
            'tags' => $this->whenLoaded('staffTags', fn (): array => $this->staffTags
                ->map(static fn (StaffTag $tag): array => [
                    'id' => $tag->getKey(),
                    'name' => $tag->name,
                ])->all()),

            'mentor' => $this->whenLoaded('mentor', fn (): ?array => $this->mentor === null ? null : [
                'id' => $this->mentor->getKey(),
                'name' => $this->mentor->name,
            ]),

            // С какого числа человек больше не работает. Пусто у работающих —
            // по этому полю экран и отличает одних от других.
            'dismissed_at' => $this->dismissed_at?->toIso8601String(),
            'dismissal_reason' => $this->dismissal_reason?->value,
            'dismissal_reason_label' => $this->dismissal_reason?->label(),

            // Superadmin, administrator or plain user.
            'level' => $this->accessLevel()->value,
            'level_label' => $this->accessLevel()->label(),

            // What was ticked for this person. Empty for an administrator, who
            // carries everything by standing rather than by grant.
            'own_permissions' => $this->permissions->pluck('name')->sort()->values()->all(),

            // Everything they can actually do. The SPA uses it to hide what
            // they cannot reach — a convenience only, since the API re-checks
            // every request.
            'permissions' => $this->effectivePermissions(),
        ];
    }

    /**
     * @return list<string>
     */
    private function effectivePermissions(): array
    {
        // An administrator passes every check through Gate::before, so listing
        // their grants would understate what they can do.
        if ($this->accessLevel()->grantsEverything()) {
            return Permission::values();
        }

        return $this->permissions->pluck('name')->sort()->values()->all();
    }
}
