<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\SyncCourseExperts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\UpdateCourseExpertsRequest;
use App\Http\Resources\Lms\CoursePersonResource;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Кто отвечает за курс — к кому идти, когда материал на вопрос не ответил.
 *
 * Право то же, что и на правку курса, а не авторское, как у доступа: назначить
 * ответственного — это сказать, к кому обращаться, а не открыть кому-то
 * закрытое. Решение это редакторское, и ограничивать его автором значило бы
 * оставлять курс без ответственного, пока автор в отпуске.
 */
final class CourseExpertController extends Controller
{
    /** Сколько человек показывает подсказка поиска. */
    private const CANDIDATES = 20;

    public function show(Course $course): AnonymousResourceCollection
    {
        Gate::authorize('update', $course);

        return CoursePersonResource::collection($this->expertsOf($course));
    }

    public function update(
        UpdateCourseExpertsRequest $request,
        Course $course,
        SyncCourseExperts $sync,
    ): AnonymousResourceCollection {
        Gate::authorize('update', $course);

        /** @var User $actor */
        $actor = $request->user();

        $sync->handle($course, $request->experts(), $actor);

        return CoursePersonResource::collection($this->expertsOf($course->refresh()));
    }

    /**
     * Кого ещё можно назначить.
     *
     * Поиском, а не списком целиком: сотрудников в компании тысячи, а нужен из
     * них один. Уже назначенные не предлагаются.
     */
    public function candidates(Request $request, Course $course): AnonymousResourceCollection
    {
        Gate::authorize('update', $course);

        $search = trim((string) $request->query('search'));

        $people = User::query()
            ->whereDoesntHave('expertCourses', fn (Builder $query) => $query->whereKey($course->getKey()))
            // Уволенного ответственным не ставят: к нему и пошли бы с вопросом,
            // на который он уже не ответит.
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
    private function expertsOf(Course $course): Collection
    {
        return $course->experts()
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->get();
    }
}
