<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\JourneyStatus;
use App\Models\OnboardingJourney;
use App\Models\OnboardingJourneyMeeting;
use App\Models\OnboardingJourneyStage;
use App\Support\Onboarding\JourneySchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Этап личного маршрута целиком: шапка, материалы, встречи, задания.
 *
 * Тот же договор, что у этапа шаблона, — экран присылает этап весь, — но
 * правит он одного человека. Строки со своим номером правятся на месте:
 * подтверждённая встреча и сделанное задание должны пережить сохранение
 * соседней строки.
 *
 * День этапа переводится в дату по календарю маршрута; маршрут без даты
 * старта берёт её от первого же этапа с днём — отсчёт начинается с дела, а не
 * с заведения карты.
 */
final readonly class SaveJourneyStage
{
    public function __construct(
        private JourneySchedule $schedule,
        private ScheduleMeeting $scheduleMeeting,
    ) {}

    /**
     * @param  array{
     *     id?: ?int,
     *     title: string,
     *     description?: ?string,
     *     day_offset?: ?int,
     *     opens_at?: ?string,
     *     position?: ?int,
     *     achievement_id?: ?int,
     *     quiz_achievement_id?: ?int,
     *     links?: list<array{type: string, id: int, is_required?: bool, day_offset?: ?int, achievement_id?: ?int}>,
     *     meetings?: list<array{id?: ?int, topic: string, format: string, place?: ?string, with_user_id?: ?int, scheduled_at?: ?string, achievement_id?: ?int}>,
     *     tasks?: list<array{id?: ?int, text: string, confirm_by: string, is_required?: bool, achievement_id?: ?int}>,
     * } $attributes
     */
    public function handle(OnboardingJourney $journey, array $attributes): OnboardingJourneyStage
    {
        return DB::transaction(function () use ($journey, $attributes): OnboardingJourneyStage {
            $stage = $this->stageOf($journey, $attributes['id'] ?? null);

            $day = $attributes['day_offset'] ?? null;

            // Первый этап с днём задаёт маршруту точку отсчёта: раньше её
            // взять было неоткуда — карта стояла пустой.
            if ($day !== null && $journey->started_at === null) {
                $journey->fill(['started_at' => CarbonImmutable::today()])->save();
            }

            $stage->fill([
                'title' => $attributes['title'],
                'due_at' => $attributes['due_at'] ?? null,
                'is_final_exam' => $attributes['is_final_exam'] ?? false,
                'responsible_ids' => $attributes['responsible_ids'] ?? [],
                'description' => $attributes['description'] ?? null,
                'day_offset' => $day,
                'opens_at' => $this->opensAt($journey, $attributes, $day, $stage),

                // Значки этого этапа: за него целиком и за сданный тест.
                'achievement_id' => $attributes['achievement_id'] ?? null,
                'quiz_achievement_id' => $attributes['quiz_achievement_id'] ?? null,
                'position' => $attributes['position']
                    ?? $stage->position
                    ?? ((int) $journey->stages()->max('position') + 1),
            ])->save();

            $this->syncLinks($stage, $attributes['links'] ?? []);
            $this->syncMeetings($stage, $attributes['meetings'] ?? []);
            $this->syncTasks($stage, $attributes['tasks'] ?? []);

            // Дописанный этап будит завершённый маршрут.
            if ($journey->status === JourneyStatus::Completed) {
                $journey->fill(['status' => JourneyStatus::Active, 'completed_at' => null])->save();
            }

            return $stage->load(
                'links.linkable', 'links.achievement',
                'meetings.counterpart', 'meetings.achievement',
                'tasks', 'tasks.achievement',
                'quiz.questions.options',
                'achievement', 'quizAchievement',
            );
        });
    }

    /**
     * Дата открытия: названная прямо, выведенная из дня — или никакая, когда
     * дни в этом маршруте не считают.
     *
     * @param  array{opens_at?: ?string}  $attributes
     */
    private function opensAt(
        OnboardingJourney $journey,
        array $attributes,
        ?int $day,
        OnboardingJourneyStage $stage,
    ): ?CarbonImmutable {
        if (array_key_exists('opens_at', $attributes) && $attributes['opens_at'] !== null) {
            return CarbonImmutable::parse($attributes['opens_at'])->startOfDay();
        }

        if ($day === null || $journey->started_at === null) {
            // Правка этапа без дня не должна отбирать уже назначенную дату:
            // её могли поставить переносом, и день тут ни при чём.
            return $day === null ? ($stage->opens_at === null ? null : CarbonImmutable::parse($stage->opens_at)) : null;
        }

        return $this->schedule->dateOfDay(
            CarbonImmutable::parse($journey->started_at->toDateString()),
            $day,
            $journey->day_mode,
        );
    }

    private function stageOf(OnboardingJourney $journey, ?int $id): OnboardingJourneyStage
    {
        if ($id === null) {
            return new OnboardingJourneyStage(['journey_id' => $journey->getKey()]);
        }

        // Свой ли этап — у маршрута, а не у базы: чужой номер заводит 404.
        return $journey->stages()->whereKey($id)->firstOrFail();
    }

    /**
     * @param  list<array{type: string, id: int, is_required?: bool, day_offset?: ?int, achievement_id?: ?int}>  $links
     */
    private function syncLinks(OnboardingJourneyStage $stage, array $links): void
    {
        $stage->links()->delete();

        foreach (array_values($links) as $position => $link) {
            $stage->links()->create([
                'linkable_type' => $link['type'],
                'linkable_id' => $link['id'],
                'position' => $position + 1,
                'is_required' => $link['is_required'] ?? true,
                'day_offset' => $link['day_offset'] ?? null,
                'achievement_id' => $link['achievement_id'] ?? null,
            ]);
        }
    }

    /**
     * Встречи: со своим номером — правка на месте (подтверждение и дата
     * остаются), без номера — новая, убранные удаляются.
     *
     * @param  list<array{id?: ?int, topic: string, format: string, place?: ?string, with_user_id?: ?int, scheduled_at?: ?string, achievement_id?: ?int}>  $meetings
     */
    private function syncMeetings(OnboardingJourneyStage $stage, array $meetings): void
    {
        $kept = [];

        foreach (array_values($meetings) as $data) {
            $meeting = isset($data['id'])
                ? $stage->meetings()->whereKey($data['id'])->first()
                : null;

            $meeting ??= new OnboardingJourneyMeeting(['journey_stage_id' => $stage->getKey()]);

            $previousParticipants = $meeting->participantIds();
            $participants = array_values(array_unique(array_map(intval(...), $data['participant_ids'] ?? array_filter([$data['with_user_id'] ?? null]))));
            $meeting->fill([
                'participant_ids' => $participants,
                'topic' => $data['topic'],
                'format' => $data['format'],
                'place' => $data['place'] ?? null,
                'with_user_id' => $participants[0] ?? null,
                'achievement_id' => $data['achievement_id'] ?? null,
            ])->save();

            $participantsChanged = $previousParticipants !== $meeting->participantIds();
            if ($participantsChanged && ! array_key_exists('scheduled_at', $data) && $meeting->scheduled_at !== null) {
                $data['scheduled_at'] = $meeting->scheduled_at->toIso8601String();
            }
            $this->keepTime($meeting, $data, $participantsChanged);

            $kept[] = $meeting->getKey();
        }

        $stage->meetings()->whereNotIn('id', $kept)->delete();
    }

    /**
     * Время встречи, присланное вместе с этапом.
     *
     * Назначение и перенос остаются одним действием: ScheduleMeeting растит
     * счётчик переносов, сбрасывает напоминание и говорит об этом обеим
     * сторонам — минуя его, встречу можно было бы тихо передвинуть у человека
     * из-под носа. Поэтому сюда попадает только то, чего оно не умеет: первая
     * дата у новой встречи приходит тем же путём, прежняя дата не трогается
     * вовсе, а стёртая просто снимается — это не перенос, а отмена времени.
     *
     * Проведённую встречу не трогаем: её время — уже история.
     *
     * @param  array{scheduled_at?: ?string}  $data
     */
    private function keepTime(OnboardingJourneyMeeting $meeting, array $data, bool $participantsChanged = false): void
    {
        if (! array_key_exists('scheduled_at', $data) || $meeting->isConfirmed()) {
            return;
        }

        if ($data['scheduled_at'] === null) {
            if ($meeting->scheduled_at !== null) {
                $meeting->fill(['scheduled_at' => null, 'reminded_at' => null])->save();
            }

            return;
        }

        $wanted = CarbonImmutable::parse($data['scheduled_at']);

        if (! $participantsChanged && $meeting->scheduled_at !== null && $wanted->equalTo($meeting->scheduled_at)) {
            return;
        }

        $this->scheduleMeeting->handle($meeting, ['scheduled_at' => $wanted->toIso8601String()]);
    }

    /**
     * @param  list<array{id?: ?int, text: string, confirm_by: string, is_required?: bool, achievement_id?: ?int}>  $tasks
     */
    private function syncTasks(OnboardingJourneyStage $stage, array $tasks): void
    {
        $kept = [];

        foreach (array_values($tasks) as $data) {
            $task = isset($data['id'])
                ? $stage->tasks()->whereKey($data['id'])->first()
                : null;

            $task ??= $stage->tasks()->make();

            $task->fill([
                'text' => $data['text'],
                'confirm_by' => $data['confirm_by'],
                'responsible_ids' => ($data['confirm_by'] === 'responsible') ? array_map(intval(...), $data['responsible_ids'] ?? []) : [],
                'is_required' => $data['is_required'] ?? true,
                'achievement_id' => $data['achievement_id'] ?? null,
            ])->save();

            $kept[] = $task->getKey();
        }

        $stage->tasks()->whereNotIn('id', $kept)->delete();
    }
}
