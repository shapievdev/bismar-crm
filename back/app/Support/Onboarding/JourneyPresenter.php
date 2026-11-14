<?php

declare(strict_types=1);

namespace App\Support\Onboarding;

use App\Http\Resources\AchievementResource;
use App\Http\Resources\Lms\QuizResource;
use App\Models\OnboardingJourney;
use App\Models\OnboardingJourneyStage;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Маршрут — в ответ API, один сборщик на сотрудника и на ведущего.
 *
 * Разница между экранами — не в цифрах, а в подробностях: ведущий видит
 * добавленные попытки и родословную этапа. Цифры (статусы, проценты, день)
 * обязаны совпадать — поэтому сборщик один, а не два.
 */
final readonly class JourneyPresenter
{
    public function __construct(
        private StageRequirements $requirements,
        private NextAction $nextAction,
        private JourneySchedule $schedule,
        private BestScore $bestScore,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(OnboardingJourney $journey, bool $forManager = false): array
    {
        $journey->loadMissing(['stages', 'user']);

        $states = $this->requirements->of($journey);
        $stages = $journey->stages;

        $completed = $stages->filter(fn ($stage): bool => $stage->isCompleted())->count();
        $total = $stages->count();

        return [
            'id' => (int) $journey->getKey(),
            'settings' => RouteSettings::of($journey->settings),
            'day_mode' => $journey->day_mode->value,
            'meeting_reminder_minutes' => $journey->meeting_reminder_minutes,
            'store' => $journey->store,
            'achievements' => $journey->user->achievements()->get()->map(fn ($badge) => AchievementResource::awarded($badge))->all(),
            'history' => $this->history($journey),
            'status' => $journey->status->value,
            'status_label' => $journey->status->label(),
            'started_at' => $journey->started_at,
            'current_day' => $this->schedule->currentDay($journey, Date::now()),
            'total_days' => $journey->started_at === null ? null : max((int) $journey->duration_days, (int) $stages->max('day_offset')),
            'employee' => [
                'id' => (int) $journey->user_id,
                'name' => (string) $journey->user?->name,
            ],
            'allow_ahead' => $journey->allow_ahead,

            // Общий прогресс — по завершённым этапам, как велит ТЗ: семь
            // равных этапов — по ~14,3% за каждый.
            'progress' => [
                'completed' => $completed,
                'total' => $total,
                'percent' => $total === 0 ? 0 : (int) round($completed / $total * 100),
            ],

            'completed_at' => $journey->completed_at,
            'next_action' => $this->nextAction->of($journey, $states),
            'stages' => $stages->map(
                fn ($stage): array => $this->stage($stage, $states[(int) $stage->getKey()] ?? null, $journey->user, $forManager),
            )->values()->all(),
        ];
    }

    private function history(OnboardingJourney $journey): array
    {
        $entries = DB::table('onboarding_activities')->where('journey_id', $journey->id)->orderByDesc('id')->limit(200)->get();
        $actors = User::withTrashed()->whereIn('id', $entries->pluck('actor_id')->filter())->get()->keyBy('id');

        return $entries->map(function ($entry) use ($actors) {
            $entry->actor_name = $actors->get($entry->actor_id)?->name;
            $entry->created_at = Date::parse($entry->created_at)->toIso8601String();

            return $entry;
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function stage(
        OnboardingJourneyStage $stage,
        ?StageState $state,
        ?User $trainee,
        bool $forManager,
    ): array {
        $items = $state?->items ?? [];

        // Лучший балл предыдущих — мотивация на карточке теста, без имён.
        foreach ($items as &$item) {
            if ($item['kind'] === 'quiz' && $trainee !== null) {
                $quiz = Quiz::query()->find($item['meta']['quiz_id'] ?? 0);
                $item['meta']['best_score_others'] = $quiz === null
                    ? null
                    : $this->bestScore->of($stage, $quiz, $trainee);
            }
        }
        unset($item);

        return [
            'id' => (int) $stage->getKey(),
            'position' => $stage->position,
            'due_at' => $stage->due_at,
            'is_final_exam' => $stage->is_final_exam,
            'responsible_ids' => $stage->responsible_ids ?? [],
            'responsibles' => User::whereIn('id', $stage->responsible_ids ?? [])->get()->map(fn ($person) => ['id' => $person->id, 'name' => $person->name, 'job_title' => $person->job_title]),
            'template_title' => $stage->template_title,
            'template_version' => $stage->template_version,
            'title' => $stage->title,
            'description' => $stage->description,
            'day_offset' => $stage->day_offset,
            'opens_at' => $stage->opens_at,
            'completed_at' => $stage->completed_at,
            // Откуда этап: снят с трафарета или придуман для этого человека.
            'from_template' => $stage->source_stage_id !== null,
            'status' => $state?->status->value,
            'status_label' => RouteSettings::of($stage->journey->settings)['status_labels'][$state?->status->value] ?? $state?->status->label(),
            'status_icon' => $state?->status->icon(),
            'progress' => $state?->progress() ?? 0,

            // Значок за весь этап. Значки отдельных дел едут в самих делах —
            // их кладёт StageRequirements в `meta`.
            'achievement_id' => $stage->achievement_id,
            'achievement' => AchievementResource::badge($stage->achievement),
            'quiz_achievement_id' => $stage->quiz_achievement_id,
            'quiz_achievement' => AchievementResource::badge($stage->quizAchievement),

            'items' => $items,
            ...($forManager ? [
                'extra_attempts' => $stage->extra_attempts,
                // Ведущему — сам тест с вопросами: он его и правит. Ключ в нём
                // прячет QuizResource по праву на ведение адаптации.
                'quiz' => $stage->quiz === null
                    ? null
                    : QuizResource::make($stage->quiz->loadMissing('questions.options', 'examiner'))->resolve(),
            ] : []),
        ];
    }
}
