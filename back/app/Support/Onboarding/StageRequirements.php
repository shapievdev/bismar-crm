<?php

declare(strict_types=1);

namespace App\Support\Onboarding;

use App\Enums\AttestationStatus;
use App\Enums\StageStatus;
use App\Http\Resources\AchievementResource;
use App\Http\Resources\Lms\QuizAttemptResource;
use App\Models\OnboardingJourney;
use App\Models\OnboardingJourneyStage;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\Lms\StepCompletion;
use App\Support\News\LinkedMaterial;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Требования этапов маршрута и их состояние — одно место на всё приложение.
 *
 * На этот ответ смотрят четверо: карта стажёра (статусы и проценты),
 * автозакрытие (RefreshJourneyProgress), кнопка следующего действия и
 * HR-экран. Разойдись они — стажёр видел бы пройденный этап и запертый
 * следующий. Поэтому и статус этапа нигде не хранится, а выводится здесь.
 *
 * Прогресс считается только по делам: материал изучен (правила StepCompletion
 * — те же, что были у плана обучения), задание подтверждено, встреча
 * подтверждена ответственным, тест сдан. Открытие страницы ничем не является.
 */
final readonly class StageRequirements
{
    public function __construct(private StepCompletion $completion) {}

    /**
     * Состояние каждого этапа журнала, по порядку карты.
     *
     * Считается пачкой: материалы одним проходом, попытки тестов одним
     * запросом — этапов с десяток, и спрашивать базу по каждому значило бы
     * умножить три запроса на десять.
     *
     * @return array<int, StageState> номер этапа журнала => состояние
     */
    public function of(OnboardingJourney $journey): array
    {
        $journey->loadMissing([
            'stages.links.linkable',
            'stages.quiz.questions',
            'stages.meetings.counterpart',
            'stages.tasks',
            'user',

            // Значки шагов: их показывает карта («за это дадут достижение») и
            // по ним же идёт выдача. Пять связей разом, потому что иначе
            // каждая строка каждого этапа спрашивала бы базу сама.
            'stages.achievement',
            'stages.quizAchievement',
            'stages.links.achievement',
            'stages.meetings.achievement',
            'stages.tasks.achievement',
        ]);

        $trainee = $journey->user;
        $stages = $journey->stages;

        $materialsDone = $this->materialsDone($trainee, $stages);
        $attempts = $this->attemptsByQuiz($trainee, $stages);

        $states = [];
        $previousCompleted = true;
        $now = Date::now();

        foreach ($stages as $stage) {
            $state = $this->stateOf($stage, $journey, $materialsDone, $attempts, $previousCompleted, $now);
            $states[(int) $stage->getKey()] = $state;
            $previousCompleted = $stage->isCompleted();
        }

        return $states;
    }

    /**
     * @param  array<string, ?CarbonImmutable>  $materialsDone
     * @param  array<int, Collection<int, QuizAttempt>>  $attempts
     */
    private function stateOf(
        OnboardingJourneyStage $stage,
        OnboardingJourney $journey,
        array $materialsDone,
        array $attempts,
        bool $previousCompleted,
        CarbonInterface $now,
    ): StageState {
        $items = [];

        $quiz = $stage->quiz;
        $hasQuiz = $quiz !== null && $quiz->questions->isNotEmpty();

        foreach ($stage->links as $link) {
            $material = $link->linkable;
            $key = $link->linkable_type.':'.$link->linkable_id;
            $doneAt = $materialsDone[$key] ?? null;

            $items[] = [
                'kind' => 'material',
                'key' => 'material-'.$link->getKey(),
                // Материал мог быть удалён после назначения: ссылка остаётся
                // строкой с честным «материала больше нет».
                'title' => $material === null ? 'Материал удалён' : LinkedMaterial::title($material),
                'is_required' => $link->is_required && $material !== null,
                'done' => $doneAt !== null,
                'done_at' => $doneAt,
                'url' => $material === null ? null : LinkedMaterial::url($material),
                'meta' => [
                    // День, к которому материал стоит изучить, — срок на виду:
                    // открытия он не решает, но неделю по нему раскладывают.
                    'day_offset' => $link->day_offset,

                    // Вид глазами экрана и номер: по ним редактор маршрута
                    // узнаёт материал в своём каталоге (документ и справочник
                    // в базе одна модель, а в каталоге — два раздела).
                    'material_type' => $link->linkable_type === 'course'
                        ? 'course'
                        : ($material?->kind?->value ?? 'document'),
                    'material_id' => (int) $link->linkable_id,

                    // Значок за этот материал: на карте — обещание, в выдаче —
                    // основание. Пусто у шага, за который значка не дают.
                    'achievement' => AchievementResource::badge($link->achievement),
                ],
            ];
        }

        foreach ($stage->meetings as $meeting) {
            $items[] = [
                'kind' => 'meeting',
                'key' => 'meeting-'.$meeting->getKey(),
                'title' => $meeting->topic,
                'is_required' => true,
                'done' => $meeting->isConfirmed(),
                'done_at' => $meeting->confirmed_at === null
                    ? null
                    : CarbonImmutable::parse($meeting->confirmed_at),
                'url' => null,
                'meta' => [
                    'id' => (int) $meeting->getKey(),
                    'with' => $meeting->counterpart === null ? null : [
                        'id' => $meeting->counterpart->getKey(),
                        'name' => $meeting->counterpart->name,
                        'job_title' => $meeting->counterpart->job_title,
                    ],
                    'format' => $meeting->format->value,
                    'format_label' => $meeting->format->label(),
                    'place' => $meeting->place,
                    'scheduled_at' => $meeting->scheduled_at,
                    'reschedules_count' => $meeting->reschedules_count,
                    'achievement' => AchievementResource::badge($meeting->achievement),
                ],
            ];
        }

        foreach ($stage->tasks as $task) {
            $items[] = [
                'kind' => 'task',
                'key' => 'task-'.$task->getKey(),
                'title' => $task->text,
                'is_required' => $task->is_required,
                'done' => $task->isDone(),
                'done_at' => $task->confirmed_at === null
                    ? null
                    : CarbonImmutable::parse($task->confirmed_at),
                'url' => null,
                'meta' => [
                    'id' => (int) $task->getKey(),
                    'confirm_by' => $task->confirm_by->value,
                    'submitted_at' => $task->submitted_at,
                    'awaiting_confirmation' => $task->submitted_at !== null && ! $task->isDone(),
                    'achievement' => AchievementResource::badge($task->achievement),
                ],
            ];
        }

        $quizPassed = null;
        $quizAwaiting = false;
        $quizFailed = false;

        if ($hasQuiz) {
            $own = $attempts[(int) $quiz->getKey()] ?? collect();

            $passedAttempt = $own->first(fn (QuizAttempt $attempt): bool => (bool) $attempt->passed);
            $quizPassed = $passedAttempt !== null;
            $quizAwaiting = $own->contains(
                fn (QuizAttempt $attempt): bool => $attempt->review_status === AttestationStatus::Pending,
            );

            // Предел попыток — правило теста плюс добавленные этому стажёру.
            $allowed = $quiz->max_attempts === null
                ? null
                : $quiz->max_attempts + $stage->extra_attempts;

            $quizFailed = ! $quizPassed
                && ! $quizAwaiting
                && $own->isNotEmpty();

            $items[] = [
                'kind' => 'quiz',
                'key' => 'quiz-'.$quiz->getKey(),
                'title' => $quiz->title,
                'is_required' => true,
                'done' => $quizPassed,
                'done_at' => $passedAttempt?->completed_at === null
                    ? null
                    : CarbonImmutable::parse($passedAttempt->completed_at),
                'url' => null,
                'meta' => [
                    'quiz_id' => (int) $quiz->getKey(),
                    'attempts_spent' => $own->count(),
                    'attempts_allowed' => $allowed,
                    'attempts_remaining' => $allowed === null ? null : max(0, $allowed - $own->count()),
                    'attempts' => QuizAttemptResource::collection($own)->resolve(),
                    'awaiting_review' => $quizAwaiting,
                    'best_score' => $own->max('score'),
                    'achievement' => AchievementResource::badge($stage->quizAchievement),
                ],
            ];
        }

        $required = array_values(array_filter($items, fn (array $item): bool => $item['is_required']));
        $requiredDone = count(array_filter($required, fn (array $item): bool => $item['done']));

        $lastDoneAt = collect($items)
            ->filter(fn (array $item): bool => $item['done'] && $item['done_at'] !== null)
            ->max('done_at');

        return new StageState(
            journeyStageId: (int) $stage->getKey(),
            status: $this->statusOf($stage, $journey, $previousCompleted, $now, $quizFailed, $requiredDone),
            items: $items,
            requiredTotal: count($required),
            requiredDone: $requiredDone,
            quizPassed: $hasQuiz ? $quizPassed : null,
            quizAwaitingReview: $quizAwaiting,
            quizFailed: $quizFailed,
            lastDoneAt: $lastDoneAt instanceof CarbonImmutable ? $lastDoneAt : null,
        );
    }

    /**
     * Пять статусов карты — в порядке старшинства.
     *
     * Пройденность старше замка: досрочно закрытый этап не должен запираться
     * задним числом. Замок старше «тест не сдан»: о несданном тесте этапа,
     * который ещё не открылся, говорить рано.
     */
    private function statusOf(
        OnboardingJourneyStage $stage,
        OnboardingJourney $journey,
        bool $previousCompleted,
        CarbonInterface $now,
        bool $quizFailed,
        int $requiredDone,
    ): StageStatus {
        if ($stage->isCompleted()) {
            return StageStatus::Completed;
        }

        if (! $stage->isOpenAt($now) || (! $journey->allow_ahead && ! $previousCompleted && $stage->opening_override_at === null)) {
            return StageStatus::Locked;
        }

        if ($quizFailed) {
            return StageStatus::QuizFailed;
        }

        return $requiredDone > 0 ? StageStatus::InProgress : StageStatus::Available;
    }

    /**
     * Когда изучен каждый материал всех этапов — одним проходом.
     *
     * @param  Collection<int, OnboardingJourneyStage>  $stages
     * @return array<string, ?CarbonImmutable>
     */
    private function materialsDone(User $trainee, Collection $stages): array
    {
        $materials = [];

        foreach ($stages as $stage) {
            foreach ($stage->links as $link) {
                $materials[] = ['type' => (string) $link->linkable_type, 'id' => (int) $link->linkable_id];
            }
        }

        if ($materials === []) {
            return [];
        }

        return array_map(
            fn (?CarbonInterface $at): ?CarbonImmutable => $at === null
                ? null
                : CarbonImmutable::parse($at),
            $this->completion->ofMaterials($trainee, $materials),
        );
    }

    /**
     * Попытки стажёра по всем тестам маршрута — одним запросом.
     *
     * @param  Collection<int, OnboardingJourneyStage>  $stages
     * @return array<int, Collection<int, QuizAttempt>>
     */
    private function attemptsByQuiz(User $trainee, Collection $stages): array
    {
        $quizIds = $stages
            ->map(fn (OnboardingJourneyStage $stage): ?int => $stage->quiz?->getKey())
            ->filter()
            ->values();

        if ($quizIds->isEmpty()) {
            return [];
        }

        return QuizAttempt::query()
            ->whereIn('quiz_id', $quizIds)
            ->where('user_id', $trainee->getKey())
            ->orderBy('completed_at')
            ->get()
            ->groupBy('quiz_id')
            ->all();
    }
}
