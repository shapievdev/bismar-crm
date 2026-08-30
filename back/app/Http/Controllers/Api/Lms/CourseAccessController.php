<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\SyncCourseAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\UpdateCourseAccessRequest;
use App\Http\Resources\Lms\CoursePersonResource;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Кого пускают в приватный курс.
 *
 * Отдельно от самого курса: править материал и решать, кто его увидит, — разные
 * решения с разными правами. Первое требует права на курсы, второе — авторства,
 * см. CoursePolicy::manageAccess.
 */
final class CourseAccessController extends Controller
{
    /** Сколько человек показывает подсказка поиска. */
    private const CANDIDATES = 20;

    public function show(Course $course): AnonymousResourceCollection
    {
        Gate::authorize('manageAccess', $course);

        return CoursePersonResource::collection($this->membersOf($course));
    }

    public function update(
        UpdateCourseAccessRequest $request,
        Course $course,
        SyncCourseAccess $syncAccess,
    ): AnonymousResourceCollection {
        Gate::authorize('manageAccess', $course);

        /** @var User $actor */
        $actor = $request->user();

        $syncAccess->handle($course, $request->members(), $actor);

        return CoursePersonResource::collection($this->membersOf($course->refresh()));
    }

    /**
     * Кого ещё можно добавить.
     *
     * Поиском, а не списком целиком: сотрудников в компании тысячи, а нужен из
     * них один. Автор и уже добавленные не предлагаются — доступ у них есть.
     */
    public function candidates(Request $request, Course $course): AnonymousResourceCollection
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

        return CoursePersonResource::collection($people);
    }

    /**
     * По фамилии, как читают список людей, — и с учётом ICU, иначе «Ёлкин»
     * оказался бы после «Яковлева»: базы собраны с C-сортировкой.
     *
     * @return Collection<int, User>
     */
    private function membersOf(Course $course): Collection
    {
        return $course->members()
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->get();
    }
}
