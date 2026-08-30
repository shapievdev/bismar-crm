<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Structure;

use App\Http\Controllers\Controller;
use App\Http\Requests\Structure\StoreDepartmentMemberRequest;
use App\Http\Requests\Structure\UpdateDepartmentMemberRequest;
use App\Http\Resources\Structure\DepartmentPersonResource;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Люди отдела: кто им руководит, кто замещает и кто в нём работает.
 *
 * Список читает всякий, кто вошёл, — это та же структура, только раскрытая.
 * Состав меняет администратор (EnsureAdministrator на маршрутах).
 */
final class DepartmentMemberController extends Controller
{
    /** Сколько человек показывает подсказка поиска. */
    private const CANDIDATES = 20;

    /**
     * Состав отдела — с поиском по имени и должности, как в панели.
     */
    public function index(Request $request, Department $department): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search'));

        $people = $department->people()
            ->when($search !== '', fn (Builder $query) => $query->matching($search))
            ->orderByRaw('COALESCE(users.last_name, users.first_name) COLLATE "und-x-icu"')
            ->get();

        return DepartmentPersonResource::collection($people);
    }

    /**
     * Добавляет людей в отдел — или меняет роль тем, кто уже в нём.
     *
     * Никого лишнего не убирает: экран посылает тех, кого назвали сейчас, а не
     * весь состав, и полная замена стёрла бы остальных. Единственное изъятие —
     * прежний отдел при переносе: перетащив человека, его оттуда забирают, и
     * обе половины делаются разом, чтобы он не потерялся между ними.
     */
    public function store(
        StoreDepartmentMemberRequest $request,
        Department $department,
    ): AnonymousResourceCollection {
        $from = $request->fromDepartmentId();

        DB::transaction(function () use ($request, $department, $from): void {
            $department->people()->syncWithoutDetaching(array_fill_keys(
                $request->userIds(),
                ['role' => $request->role()->value],
            ));

            if ($from !== null && $from !== (int) $department->getKey()) {
                Department::query()->whereKey($from)->first()?->people()->detach($request->userIds());
            }
        });

        return $this->index($request, $department->refresh());
    }

    /**
     * Смена роли: тот же человек, другое место в отделе.
     */
    public function update(
        UpdateDepartmentMemberRequest $request,
        Department $department,
        User $user,
    ): AnonymousResourceCollection {
        abort_unless($department->people()->whereKey($user->getKey())->exists(), 404);

        $department->people()->updateExistingPivot($user->getKey(), [
            'role' => $request->role()->value,
        ]);

        return $this->index($request, $department->refresh());
    }

    public function destroy(Request $request, Department $department, User $user): AnonymousResourceCollection
    {
        $department->people()->detach($user->getKey());

        return $this->index($request, $department->refresh());
    }

    /**
     * Кого можно добавить: работающие сотрудники, поиском.
     *
     * Уже состоящие в этом отделе не отсеиваются — их «добавление» и есть
     * смена роли, и прятать их значило бы прятать единственный способ
     * назначить сотрудника руководителем.
     */
    public function candidates(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search'));

        // Роли у кандидата нет: пока его не добавили, он в отделе никем не
        // числится, — и ресурс это переживает, см. DepartmentPersonResource.
        $people = User::query()
            ->employed()
            ->when($search !== '', fn (Builder $query) => $query->matching($search))
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->limit(self::CANDIDATES)
            ->get();

        return DepartmentPersonResource::collection($people);
    }
}
