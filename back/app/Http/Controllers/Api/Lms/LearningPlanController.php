<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\SyncLearningPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\UpdateLearningPlanRequest;
use App\Http\Resources\Lms\LearningPlanItemResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LearningPlanItem;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Lms\LearningPlan;
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
 * Порядок — запрет, а не совет (решение пользователя 2026-09-05): курс
 * открывается, когда до него дошла очередь. Само правило живёт в LearningPlan,
 * здесь оно только показано — отсюда `is_locked` у шага. Пройденность шага
 * экран и запрет берут из одного места (StepCompletion): разойдись они, план
 * рисовал бы галочку там, где каталог держит замок.
 */
final class LearningPlanController extends Controller
{
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

        $plan = LearningPlan::over($learner, $items);

        return LearningPlanItemResource::collection(
            $this->markLocks($this->withProgress($items, $learner, $plan), $learner, $plan),
        );
    }

    /**
     * План сотрудника — глазами того, кто его составляет.
     */
    public function show(User $user): AnonymousResourceCollection
    {
        $items = $user->planItems()->with('plannable', 'assignedBy')->get();

        return LearningPlanItemResource::collection(
            $this->markVisibility($this->withProgress($items, $user, LearningPlan::over($user, $items)), $user),
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
     * Прикладывает к каждому шагу прогресс этого сотрудника.
     *
     * Записи на курс может не быть вовсе, и отметки о прочтении регламента
     * тоже, — это не пустота в данных, а честный ноль.
     *
     * Доля пройденного считается здесь, а вот «пройден ли шаг» приходит из
     * плана: этим же ответом открываются курсы, и второго мнения о нём быть
     * не должно.
     *
     * @param  Collection<int, LearningPlanItem>  $items
     * @return Collection<int, LearningPlanItem>
     */
    private function withProgress(Collection $items, User $learner, LearningPlan $plan): Collection
    {
        $enrollments = Enrollment::query()
            ->where('user_id', $learner->getKey())
            ->whereIn('course_id', $this->idsOf($items, Course::class))
            ->with('completions')
            ->get()
            ->keyBy('course_id');

        $quizzes = $this->quizOutcomes($items, $learner);

        return $items->each(function (LearningPlanItem $item) use ($enrollments, $plan, $quizzes): void {
            $item->plannable instanceof Regulation
                ? $this->attachRegulationProgress($item, $plan)
                : $this->attachCourseProgress($item, $enrollments, $plan);

            // Не просто «пройден», а когда: у пройденного шага дата — это то,
            // о чём спрашивают вторым вопросом после «пройдено ли».
            $item->setAttribute('is_completed', $plan->isCompleted($item));
            $item->setAttribute('completed_at', $plan->completedAt($item)?->toIso8601String());
            $item->setAttribute('quiz', $quizzes[(int) $item->plannable_id] ?? null);
        });
    }

    /**
     * Как этот человек прошёл проверки при документах плана.
     *
     * Только у документов: у курса тест висит на уроке, и о нём говорит доля
     * пройденного, — а у документа проверка и есть всё его прохождение, и «не
     * ознакомлен» без неё выглядел бы ленью, а не несданным тестом.
     *
     * Двумя запросами на весь план, а не по запросу на шаг: проверок и попыток
     * в плане из десяти шагов — десятки строк.
     *
     * @param  Collection<int, LearningPlanItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function quizOutcomes(Collection $items, User $learner): array
    {
        $documentIds = $this->idsOf($items, Regulation::class);

        if ($documentIds === []) {
            return [];
        }

        $quizzes = Quiz::query()
            ->where('quizzable_type', (new Regulation)->getMorphClass())
            ->whereIn('quizzable_id', $documentIds)
            ->withCount('questions')
            ->get();

        if ($quizzes->isEmpty()) {
            return [];
        }

        $attempts = QuizAttempt::query()
            ->whereIn('quiz_id', $quizzes->modelKeys())
            ->where('user_id', $learner->getKey())
            ->get(['quiz_id', 'score', 'passed', 'completed_at'])
            ->groupBy('quiz_id');

        return $quizzes->mapWithKeys(function (Quiz $quiz) use ($attempts): array {
            /** @var \Illuminate\Support\Collection<int, QuizAttempt> $own */
            $own = $attempts->get($quiz->getKey(), collect());

            return [(int) $quiz->quizzable_id => [
                'questions' => (int) $quiz->questions_count,
                'attempts' => $own->count(),
                // Лучшая попытка, а не последняя: сдал — значит сдал, и
                // повторный заход из любопытства этого не отменяет.
                'best_score' => $own->max('score'),
                'passed' => $own->contains(fn (QuizAttempt $attempt): bool => (bool) $attempt->passed),
                'last_at' => $own->max('completed_at')?->toIso8601String(),
            ]];
        })->all();
    }

    /**
     * У курса прогресс — доля пройденных уроков.
     *
     * @param  \Illuminate\Support\Collection<int, Enrollment>  $enrollments
     */
    private function attachCourseProgress(LearningPlanItem $item, $enrollments, LearningPlan $plan): void
    {
        $enrollment = $enrollments->get($item->plannable_id);

        // Курс у шага уже загружен — отдаём его расчёту, чтобы тот не ходил за
        // тем же самым второй раз на каждую строку плана.
        if ($enrollment !== null && $item->plannable instanceof Course) {
            $enrollment->setRelation('course', $item->plannable);
        }

        $item->setAttribute('progress_percentage', $enrollment === null ? 0 : $this->progress->percentage($enrollment));
        $item->setAttribute('is_started', $enrollment?->started_at !== null);
    }

    /**
     * У документа прогресса как доли нет: правило либо пройдено, либо нет.
     *
     * Пройдено — это ознакомление и, если при документе есть проверка, сданный
     * тест; так считает StepCompletion, и здесь доля просто повторяет его
     * ответ, чтобы кольцо прогресса не спорило с галочкой рядом.
     */
    private function attachRegulationProgress(LearningPlanItem $item, LearningPlan $plan): void
    {
        $done = $plan->isCompleted($item);

        $item->setAttribute('progress_percentage', $done ? 100 : 0);
        // «Начал читать» документ — состояние, которого не существует: он на
        // одну страницу, и середины у него нет.
        $item->setAttribute('is_started', $done);
    }

    /**
     * Отмечает шаги, до которых очередь ещё не дошла.
     *
     * Только в своём плане: составителю замки не нужны — он смотрит, что
     * назначено, а не что открыто, — и считается правило по тому, кто учится.
     *
     * Шаг с документом не запирается: очередь держит только курсы, а к правилу
     * компании приходят за ответом и посреди чужого обучения.
     *
     * @param  Collection<int, LearningPlanItem>  $items
     * @return Collection<int, LearningPlanItem>
     */
    private function markLocks(Collection $items, User $learner, LearningPlan $plan): Collection
    {
        $restrained = LearningPlan::restrains($learner);

        return $items->each(fn (LearningPlanItem $item) => $item->setAttribute(
            'is_locked',
            $restrained
                && $item->plannable instanceof Course
                && ! $plan->allows($item->plannable),
        ));
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
