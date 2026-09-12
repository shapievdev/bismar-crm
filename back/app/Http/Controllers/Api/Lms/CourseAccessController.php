<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\SyncCourseAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\UpdateMaterialAccessRequest;
use App\Http\Resources\GroupResource;
use App\Http\Resources\Lms\CoursePersonResource;
use App\Models\Course;
use App\Models\Department;
use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Кого пускают в приватный курс.
 *
 * Отдельно от самого курса: править материал и решать, кто его увидит, — разные
 * решения с разными правами. Первое требует права на курсы, второе — авторства,
 * см. CoursePolicy::manageAccess.
 *
 * Пускают двумя способами сразу — поимённо и группами (решение пользователя
 * 2026-09-12), — поэтому и список, и подсказка поиска отвечают двумя частями:
 * `people` и `groups`. Порознь их не спрашивают: на экране это одна панель.
 */
final class CourseAccessController extends Controller
{
    /** Сколько строк показывает подсказка поиска — людей и групп поровну. */
    private const CANDIDATES = 20;

    public function show(Course $course): JsonResponse
    {
        Gate::authorize('manageAccess', $course);

        return $this->listing($course);
    }

    public function update(
        UpdateMaterialAccessRequest $request,
        Course $course,
        SyncCourseAccess $syncAccess,
    ): JsonResponse {
        Gate::authorize('manageAccess', $course);

        /** @var User $actor */
        $actor = $request->user();

        $syncAccess->handle(
            $course,
            $request->members(),
            $request->groups(),
            $request->departments(),
            $actor,
        );

        return $this->listing($course->refresh());
    }

    /**
     * Кого ещё можно добавить.
     *
     * Поиском, а не списком целиком: сотрудников в компании тысячи, а нужен из
     * них один. Автор и уже добавленные не предлагаются — доступ у них есть.
     *
     * Группы ищутся тем же словом и тем же запросом: человек набирает «продаж»
     * и видит разом отдел продаж группой и Продажникова поимённо, не выбирая
     * заранее, кого он ищет.
     */
    public function candidates(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('manageAccess', $course);

        $search = trim((string) $request->query('search'));

        $people = User::query()
            ->whereKeyNot($course->author_id ?? 0)
            ->whereDoesntHave('admittedCourses', fn (Builder $query) => $query->whereKey($course->getKey()))
            // Уволенных не предлагают: платформа для них закрыта, и допуск
            // ничего бы им не открыл.
            ->employed()
            ->when($search !== '', fn (Builder $query) => $query->matching($search))
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->limit(self::CANDIDATES)
            ->get();

        $groups = Group::query()
            ->whereNotIn('id', $course->memberGroups()->select('groups.id'))
            ->matching($search)
            ->withCount('people')
            ->ordered()
            ->limit(self::CANDIDATES)
            ->get();

        $departments = Department::query()
            ->whereNotIn('id', $course->memberDepartments()->select('departments.id'))
            ->matching($search)
            ->with('parent:id,name')
            ->ordered()
            ->limit(self::CANDIDATES)
            ->get();

        return response()->json([
            'data' => [
                'people' => CoursePersonResource::collection($people)->resolve(),
                'groups' => GroupResource::collection($groups)->resolve(),
                'departments' => $this->departments($departments),
            ],
        ]);
    }

    /**
     * Допущенные — людьми и группами.
     *
     * Люди по фамилии, как читают список людей, группы по названию, и то и
     * другое с учётом ICU: базы собраны с C-сортировкой, где «Ёлкин» оказался
     * бы после «Яковлева».
     */
    private function listing(Course $course): JsonResponse
    {
        $people = $course->members()
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->get();

        $groups = $course->memberGroups()->withCount('people')->ordered()->get();
        $departments = $course->memberDepartments()->with('parent:id,name')->ordered()->get();

        return response()->json([
            'data' => [
                'people' => CoursePersonResource::collection($people)->resolve(),
                'groups' => GroupResource::collection($groups)->resolve(),
                'departments' => $this->departments($departments),
            ],
        ]);
    }

    /**
     * Отделы — именем и тем, чей это отдел.
     *
     * Родитель приложен не для красоты: «Продажи» в рознице и «Продажи» в опте
     * — разные отделы, и в списке из одних названий их не различить. Полного
     * дерева здесь не нужно: его рисуют там, где отделы выбирают из структуры.
     *
     * @param  Collection<int, Department>  $departments
     * @return list<array<string, mixed>>
     */
    private function departments(Collection $departments): array
    {
        return $departments->map(static fn (Department $department): array => [
            'id' => (int) $department->getKey(),
            'name' => $department->name,
            'parent' => $department->parent?->name,
        ])->values()->all();
    }
}
