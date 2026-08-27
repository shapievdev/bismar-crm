<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\SyncLearningPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\UpdateLearningPlanRequest;
use App\Http\Resources\Lms\CoursePersonResource;
use App\Http\Resources\Lms\LearningPlanItemResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LearningPlanItem;
use App\Models\User;
use App\Support\Lms\ProgressCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * План обучения: что сотруднику назначили пройти и в каком порядке.
 *
 * Два разных зрителя, и потому два набора маршрутов. Свой план читает всякий,
 * кому открыта база знаний, — это его собственное дело. Чужой план читает и
 * правит тот, кому доверено вести обучение (`enrollments.manage`); право на
 * курсы к этому не сводится: собрать материал и решать, кто его пройдёт, —
 * разные решения.
 *
 * Порядок здесь — совет, а не запрет: сотрудник волен открыть любой шаг
 * (решение пользователя 2026-08-27). Ничто в этих маршрутах доступа к курсу не
 * добавляет и не отнимает — его по-прежнему решает CoursePolicy.
 */
final class LearningPlanController extends Controller
{
    /** Сколько человек показывает подсказка поиска. */
    private const CANDIDATES = 20;

    public function __construct(private readonly ProgressCalculator $progress) {}

    /**
     * План того, кто спрашивает.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        /** @var User $learner */
        $learner = $request->user();

        $items = $learner->planItems()
            // Курс могли закрыть или убрать в корзину уже после назначения.
            // Показывать нечего: материала за шагом для этого человека нет, а
            // строка «курс, которого вы не видите» ему ничего не объясняет.
            ->whereHas('course', fn (Builder $query) => $query->visibleTo($learner))
            ->with('course.author', 'course.category', 'assignedBy')
            ->get();

        return LearningPlanItemResource::collection($this->withProgress($items, $learner));
    }

    /**
     * План сотрудника — глазами того, кто его составляет.
     */
    public function show(User $user): AnonymousResourceCollection
    {
        $items = $user->planItems()
            ->with('course.author', 'course.category', 'assignedBy')
            ->get();

        return LearningPlanItemResource::collection(
            $this->markVisibility($this->withProgress($items, $user), $user),
        );
    }

    public function update(
        UpdateLearningPlanRequest $request,
        User $user,
        SyncLearningPlan $syncPlan,
    ): AnonymousResourceCollection {
        /** @var User $actor */
        $actor = $request->user();

        $syncPlan->handle($user, $request->courses(), $actor);

        return $this->show($user->refresh());
    }

    /**
     * Кому можно назначить план.
     *
     * Поиском, а не списком целиком, по той же причине, что и на экране
     * доступа к курсу: сотрудников тысячи, а нужен из них один.
     */
    public function people(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search'));

        $people = User::query()
            ->when($search !== '', fn (Builder $query) => $query->matching($search))
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->limit(self::CANDIDATES)
            ->get();

        return CoursePersonResource::collection($people);
    }

    /**
     * Прикладывает к каждому шагу прогресс этого сотрудника по его курсу.
     *
     * Записи на курс может не быть вовсе — назначенное ещё не открывали, — и
     * это не пустота в данных, а честный ноль.
     *
     * @param  Collection<int, LearningPlanItem>  $items
     * @return Collection<int, LearningPlanItem>
     */
    private function withProgress(Collection $items, User $learner): Collection
    {
        $enrollments = Enrollment::query()
            ->where('user_id', $learner->getKey())
            ->whereIn('course_id', $items->pluck('course_id')->all())
            ->with('completions')
            ->get()
            ->keyBy('course_id');

        return $items->each(function (LearningPlanItem $item) use ($enrollments): void {
            $enrollment = $enrollments->get($item->course_id);

            // Курс у шага уже загружен — отдаём его расчёту, чтобы тот не
            // ходил за тем же самым второй раз на каждую строку плана.
            $enrollment?->setRelation('course', $item->course);

            $item->setAttribute('progress_percentage', $enrollment === null ? 0 : $this->progress->percentage($enrollment));
            $item->setAttribute('is_started', $enrollment?->started_at !== null);
            $item->setAttribute('is_completed', $enrollment?->isCompleted() ?? false);
        });
    }

    /**
     * Отмечает шаги, которых сотрудник у себя не увидит.
     *
     * @param  Collection<int, LearningPlanItem>  $items
     * @return Collection<int, LearningPlanItem>
     */
    private function markVisibility(Collection $items, User $learner): Collection
    {
        $visible = Course::query()
            ->visibleTo($learner)
            ->whereKey($items->pluck('course_id')->all())
            ->pluck('id')
            ->map(intval(...))
            ->all();

        return $items->each(fn (LearningPlanItem $item) => $item->setAttribute(
            'is_visible_to_learner',
            in_array((int) $item->course_id, $visible, strict: true),
        ));
    }
}
