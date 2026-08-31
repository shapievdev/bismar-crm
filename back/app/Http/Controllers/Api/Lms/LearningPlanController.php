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
use App\Models\Regulation;
use App\Models\RegulationAcknowledgement;
use App\Models\User;
use App\Support\Lms\PlannableMaterial;
use App\Support\Lms\ProgressCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * План обучения: что сотруднику назначили пройти и в каком порядке.
 *
 * Шаг — курс или регламент. Прогресс у них считается по-разному и потому здесь
 * два правила: у курса это доля пройденных уроков, у регламента — прочитал или
 * нет, третьего у правила не бывает.
 *
 * Два разных зрителя, и потому два набора маршрутов. Свой план читает всякий,
 * кому открыта база знаний, — это его собственное дело. Чужой план читает и
 * правит тот, кому доверено вести обучение (`enrollments.manage`).
 *
 * Порядок здесь — совет, а не запрет: сотрудник волен открыть любой шаг
 * (решение пользователя 2026-08-27).
 */
final class LearningPlanController extends Controller
{
    /** Сколько человек показывает подсказка поиска. */
    private const CANDIDATES = 20;

    /** Что вообще бывает шагом плана. */
    private const KINDS = [Course::class, Regulation::class];

    public function __construct(
        private readonly ProgressCalculator $progress,
        private readonly PlannableMaterial $material,
    ) {}

    /**
     * План того, кто спрашивает.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        /** @var User $learner */
        $learner = $request->user();

        $items = $learner->planItems()
            // Материал могли закрыть или убрать в корзину уже после
            // назначения. Показывать нечего: за шагом для этого человека нет
            // ничего, а строка «то, чего вы не видите» ему не объясняет.
            //
            // Условие одно на оба вида: у курса и у регламента область чтения
            // называется одинаково — scopeVisibleTo.
            ->whereHasMorph(
                'plannable',
                self::KINDS,
                fn (Builder $query) => $query->visibleTo($learner),
            )
            ->with('plannable', 'assignedBy')
            ->get();

        return LearningPlanItemResource::collection($this->withProgress($items, $learner));
    }

    /**
     * План сотрудника — глазами того, кто его составляет.
     */
    public function show(User $user): AnonymousResourceCollection
    {
        $items = $user->planItems()->with('plannable', 'assignedBy')->get();

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

        $syncPlan->handle($user, $request->items(), $actor);

        return $this->show($user->refresh());
    }

    /**
     * Что можно назначить этому сотруднику — весь список, а не поиском.
     *
     * План составляют, глядя на то, что есть: курсов и регламентов десятки, и
     * они приходят разом с категорией у каждого, чтобы экран сузил список сам,
     * не спрашивая сервер на каждое движение. См. PlannableMaterial.
     */
    public function material(Request $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->material->catalogue($actor, $user)]);
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
            // Уволенным не назначают: пройти назначенное им уже негде.
            ->employed()
            ->when($search !== '', fn (Builder $query) => $query->matching($search))
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->limit(self::CANDIDATES)
            ->get();

        return CoursePersonResource::collection($people);
    }

    /**
     * Прикладывает к каждому шагу прогресс этого сотрудника.
     *
     * Записи на курс может не быть вовсе, и отметки о прочтении регламента
     * тоже, — это не пустота в данных, а честный ноль.
     *
     * @param  Collection<int, LearningPlanItem>  $items
     * @return Collection<int, LearningPlanItem>
     */
    private function withProgress(Collection $items, User $learner): Collection
    {
        $enrollments = Enrollment::query()
            ->where('user_id', $learner->getKey())
            ->whereIn('course_id', $this->idsOf($items, Course::class))
            ->with('completions')
            ->get()
            ->keyBy('course_id');

        $acknowledged = RegulationAcknowledgement::query()
            ->where('user_id', $learner->getKey())
            ->whereIn('regulation_id', $this->idsOf($items, Regulation::class))
            ->pluck('regulation_id')
            ->map(intval(...))
            ->all();

        return $items->each(function (LearningPlanItem $item) use ($enrollments, $acknowledged): void {
            $item->plannable instanceof Regulation
                ? $this->attachRegulationProgress($item, $acknowledged)
                : $this->attachCourseProgress($item, $enrollments);
        });
    }

    /**
     * У курса прогресс — доля пройденных уроков.
     *
     * @param  \Illuminate\Support\Collection<int, Enrollment>  $enrollments
     */
    private function attachCourseProgress(LearningPlanItem $item, $enrollments): void
    {
        $enrollment = $enrollments->get($item->plannable_id);

        // Курс у шага уже загружен — отдаём его расчёту, чтобы тот не ходил за
        // тем же самым второй раз на каждую строку плана.
        if ($enrollment !== null && $item->plannable instanceof Course) {
            $enrollment->setRelation('course', $item->plannable);
        }

        $item->setAttribute('progress_percentage', $enrollment === null ? 0 : $this->progress->percentage($enrollment));
        $item->setAttribute('is_started', $enrollment?->started_at !== null);
        $item->setAttribute('is_completed', $enrollment?->isCompleted() ?? false);
    }

    /**
     * У регламента прогресса как доли нет: правило либо прочитано, либо нет.
     *
     * @param  list<int>  $acknowledged
     */
    private function attachRegulationProgress(LearningPlanItem $item, array $acknowledged): void
    {
        $done = in_array((int) $item->plannable_id, $acknowledged, strict: true);

        $item->setAttribute('progress_percentage', $done ? 100 : 0);
        // «Начал читать» регламент — состояние, которого не существует: он на
        // одну страницу, и середины у него нет.
        $item->setAttribute('is_started', $done);
        $item->setAttribute('is_completed', $done);
    }

    /**
     * Отмечает шаги, которых сотрудник у себя не увидит.
     *
     * @param  Collection<int, LearningPlanItem>  $items
     * @return Collection<int, LearningPlanItem>
     */
    private function markVisibility(Collection $items, User $learner): Collection
    {
        $visible = [];

        foreach (self::KINDS as $model) {
            $ids = $this->idsOf($items, $model);

            if ($ids === []) {
                continue;
            }

            /** @var Builder<Course|Regulation> $query */
            $query = $model::query();

            $visible[(new $model)->getMorphClass()] = $query->visibleTo($learner)
                ->whereKey($ids)
                ->pluck('id')
                ->map(intval(...))
                ->all();
        }

        return $items->each(fn (LearningPlanItem $item) => $item->setAttribute(
            'is_visible_to_learner',
            in_array(
                (int) $item->plannable_id,
                $visible[$item->plannable_type] ?? [],
                strict: true,
            ),
        ));
    }

    /**
     * Номера шагов одного вида.
     *
     * @param  Collection<int, LearningPlanItem>  $items
     * @param  class-string  $model
     * @return list<int>
     */
    private function idsOf(Collection $items, string $model): array
    {
        $type = (new $model)->getMorphClass();

        return $items
            ->where('plannable_type', $type)
            ->pluck('plannable_id')
            ->map(intval(...))
            ->values()
            ->all();
    }
}
