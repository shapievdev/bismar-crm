<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Lms\SaveQuiz;
use App\Enums\JourneyStatus;
use App\Enums\ScheduleAnchor;
use App\Exceptions\ConflictException;
use App\Jobs\SendPush;
use App\Models\OnboardingJourney;
use App\Models\OnboardingJourneyStage;
use App\Models\OnboardingStage;
use App\Models\OnboardingStageMeeting;
use App\Models\OnboardingTemplate;
use App\Models\User;
use App\Support\Onboarding\JourneySchedule;
use App\Support\Onboarding\ProfessionalKit;
use App\Support\Push\PushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Накладывает шаблон на личный маршрут: снимает с трафарета копию этапов.
 *
 * Дописывает в конец, а не заменяет (решение пользователя 2026-10-26): в один
 * маршрут кладут и ввод в должность, и перевод в другой магазин, и пройденное
 * при этом не должно пропадать. Скопированное дальше живёт само — правка
 * шаблона его не достаёт, правка этапа не достаёт шаблон.
 *
 * Дни шаблона относительные, и во что они превратятся, решает точка отсчёта
 * (ScheduleAnchor): дата приёма, названная дата, сегодня — или ничего, когда
 * даты расставят потом руками.
 */
final readonly class ApplyTemplate
{
    public function __construct(
        private JourneySchedule $schedule,
        private SaveQuiz $saveQuiz,
    ) {}

    /**
     * @param  array{anchor?: string, date?: ?string}  $attributes
     *
     * @throws ConflictException
     */
    public function handle(
        OnboardingJourney $journey,
        OnboardingTemplate $template,
        array $attributes,
        User $actor,
    ): OnboardingJourney {
        if (! $template->status->isAssignable()) {
            throw new ConflictException('Накладывать можно только опубликованный шаблон.');
        }

        $stages = $template->stages()->with(['links', 'meetings', 'tasks', 'quiz.questions.options'])->get();

        if ($stages->isEmpty()) {
            throw new ConflictException('В шаблоне нет этапов — накладывать нечего.');
        }

        $anchor = ScheduleAnchor::from($attributes['anchor'] ?? ScheduleAnchor::Today->value);
        $employee = $journey->loadMissing('user')->user;

        $start = $this->startOf($anchor, $attributes['date'] ?? null, $employee);

        $added = DB::transaction(function () use ($journey, $template, $stages, $anchor, $start, $employee): int {
            /*
             * Правила счёта снимаются с шаблона при первом наложении: пустому
             * маршруту их взять неоткуда. Дальше они принадлежат маршруту —
             * второй шаблон чужой ритм ему не навязывает.
             */
            if ($journey->isEmpty()) {
                $journey->fill([
                    'day_mode' => $template->day_mode,
                    'duration_days' => $template->duration_days,
                    'settings' => $template->settings,
                    'requires_graduation' => (bool) $template->requires_graduation,
                    'kit_items' => ProfessionalKit::receipts($journey) === []
                        ? ProfessionalKit::definitions($template->kit_items)
                        : ProfessionalKit::definitions($journey->kit_items),
                    'graduation_achievement_id' => $template->graduation_achievement_id,
                    'store' => $template->store,
                    'allow_ahead' => $template->allow_ahead,
                    'meeting_reminder_minutes' => $template->meeting_reminder_minutes,
                ]);
            }

            // Дата старта — у первого расписания: дальше она уже выбрана, и
            // второй шаблон не должен передвигать прошлые этапы.
            if ($start !== null && $journey->started_at === null) {
                $journey->fill(['started_at' => $start]);
            }

            // Дописанный этап будит завершённый маршрут: дел снова прибавилось.
            if ($journey->status === JourneyStatus::Completed) {
                $journey->fill(['status' => JourneyStatus::Active, 'completed_at' => null]);
            }

            $journey->save();

            $position = (int) $journey->stages()->max('position');

            foreach ($stages as $stage) {
                $position++;
                $this->copyStage($stage, $journey, $position, $anchor, $start, $employee);
            }

            return $stages->count();
        });

        $this->tellEmployee($journey, $template, $actor, $added);

        return $journey->load('stages');
    }

    /**
     * Дата, от которой считаются дни. Null — дни не считаются вовсе.
     *
     * @throws ConflictException
     */
    private function startOf(ScheduleAnchor $anchor, ?string $date, ?User $employee): ?CarbonImmutable
    {
        return match ($anchor) {
            ScheduleAnchor::None => null,
            ScheduleAnchor::Today => CarbonImmutable::today(),
            ScheduleAnchor::Date => $date === null
                ? throw new ConflictException('Выберите дату, от которой считать дни.')
                : CarbonImmutable::parse($date)->startOfDay(),
            ScheduleAnchor::Hired => $employee?->hired_at === null
                ? throw new ConflictException('У сотрудника не заполнена дата приёма — выберите другую точку отсчёта.')
                : CarbonImmutable::parse($employee->hired_at->toDateString()),
        };
    }

    private function copyStage(
        OnboardingStage $stage,
        OnboardingJourney $journey,
        int $position,
        ScheduleAnchor $anchor,
        ?CarbonImmutable $start,
        ?User $employee,
    ): void {
        $opensAt = $start === null
            ? null
            : $this->schedule->dateOfDay($start, $stage->day_offset, $journey->day_mode);

        $copy = $journey->stages()->create([
            'source_stage_id' => $stage->getKey(),
            'template_id' => $stage->template_id,
            'template_version' => $stage->template->version,
            'template_title' => $stage->template->title,
            'is_final_exam' => $stage->is_final_exam,
            'responsible_ids' => $stage->responsible_ids,
            'position' => $position,
            'title' => $stage->title,
            'description' => $stage->description,
            'day_offset' => $anchor === ScheduleAnchor::None ? null : $stage->day_offset,
            'opens_at' => $opensAt,

            // Значки переезжают вместе с содержимым: трафарет решает, за что
            // их дают, а выдаёт их уже личный этап.
            'achievement_id' => $stage->achievement_id,
            'quiz_achievement_id' => $stage->quiz_achievement_id,
        ]);

        foreach ($stage->links as $link) {
            $copy->links()->create([
                'linkable_type' => $link->linkable_type,
                'linkable_id' => $link->linkable_id,
                'position' => $link->position,
                'is_required' => $link->is_required,
                'day_offset' => $anchor === ScheduleAnchor::None ? null : $link->day_offset,
                'achievement_id' => $link->achievement_id,
            ]);
        }

        foreach ($stage->meetings as $meeting) {
            $copy->meetings()->create([
                'stage_meeting_id' => $meeting->getKey(),
                'topic' => $meeting->topic,
                'format' => $meeting->format,
                'place' => $meeting->place,
                'with_user_id' => $this->meetingCounterpart($meeting, $employee),
                // Время не назначается само: день знает этап, а час — живые
                // люди. До назначения встреча стоит «время уточняется».
                'scheduled_at' => null,
                'achievement_id' => $meeting->achievement_id,
            ]);
        }

        foreach ($stage->tasks as $task) {
            $copy->tasks()->create([
                'stage_task_id' => $task->getKey(),
                'text' => $task->text,
                'confirm_by' => $task->confirm_by,
                'is_required' => $task->is_required,
                'achievement_id' => $task->achievement_id,
            ]);
        }

        $this->copyQuiz($stage, $copy);
    }

    /**
     * Тест копируется вопросами, а не ссылкой: у человека он свой, и попытки
     * ложатся на его копию. Номеров вопросов копия не наследует — история
     * трафарета к ней не относится.
     */
    private function copyQuiz(OnboardingStage $stage, OnboardingJourneyStage $copy): void
    {
        $quiz = $stage->quiz;

        if ($quiz === null || $quiz->questions->isEmpty()) {
            return;
        }

        $this->saveQuiz->handle($copy, [
            'title' => $quiz->title,
            'description' => $quiz->description,
            'passing_score' => $quiz->passing_score,
            'max_attempts' => $quiz->max_attempts,
            'kind' => $quiz->kind->value,
            'examiner_id' => $quiz->examiner_id,
            'questions' => $quiz->questions->map(fn ($question): array => [
                'text' => $question->text,
                'type' => $question->type->value,
                'points' => $question->points,
                'expected_answer' => $question->expected_answer,
                'table' => $question->table,
                'options' => $question->options->map(fn ($option): array => [
                    'text' => $option->text,
                    'is_correct' => $option->is_correct,
                ])->all(),
            ])->all(),
        ]);
    }

    private function meetingCounterpart(OnboardingStageMeeting $meeting, ?User $employee): ?int
    {
        if ($meeting->with_role === OnboardingStageMeeting::ROLE_MENTOR) {
            return $employee?->mentor_id;
        }

        return $meeting->with_user_id;
    }

    private function tellEmployee(OnboardingJourney $journey, OnboardingTemplate $template, User $actor, int $added): void
    {
        if ($journey->user_id === $actor->getKey()) {
            return;
        }

        SendPush::dispatch([(int) $journey->user_id], new PushMessage(
            title: 'В вашем маршруте новые этапы',
            body: PushMessage::shorten(sprintf('%s — добавлено этапов: %d.', $template->title, $added)),
            url: '/onboarding',
            tag: 'onboarding-'.$journey->getKey(),
        ));
    }
}
