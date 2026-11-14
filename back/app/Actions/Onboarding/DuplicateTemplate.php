<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Lms\SaveQuiz;
use App\Enums\OnboardingTemplateStatus;
use App\Models\OnboardingStage;
use App\Models\OnboardingTemplate;
use App\Models\User;
use App\Support\SlugGenerator;
use Illuminate\Support\Facades\DB;

/**
 * «Версия» маршрута — его глубокая копия: этапы, материалы, встречи, задания
 * и тесты с вопросами.
 *
 * Копия заводится черновиком: её делают, чтобы поменять, а не чтобы назначать
 * как есть. Вопросы копируются через SaveQuiz — у него и живёт правило «как
 * устроен тест», и второй переписчик вопросов разошёлся бы с ним.
 */
final readonly class DuplicateTemplate
{
    public function __construct(
        private SlugGenerator $slugs,
        private SaveQuiz $saveQuiz,
    ) {}

    public function handle(OnboardingTemplate $template, User $actor): OnboardingTemplate
    {
        return DB::transaction(function () use ($template, $actor): OnboardingTemplate {
            $root = OnboardingTemplate::withTrashed()->lockForUpdate()->findOrFail($template->parent_id ?? $template->getKey());
            $version = max((int) $root->version, (int) OnboardingTemplate::withTrashed()->where('parent_id', $root->getKey())->max('version')) + 1;
            $copy = OnboardingTemplate::create([
                'parent_id' => $root->getKey(),
                'version' => $version,
                'job_title' => $template->job_title,
                'store' => $template->store,
                'settings' => $template->settings,
                'requires_graduation' => false,
                'title' => $template->title.' (копия)',
                'slug' => $this->slugs->generate($template->title.' копия', OnboardingTemplate::class),
                'description' => $template->description,
                'status' => OnboardingTemplateStatus::Draft,
                'duration_days' => $template->duration_days,
                'day_mode' => $template->day_mode,
                'allow_ahead' => $template->allow_ahead,
                'meeting_reminder_minutes' => $template->meeting_reminder_minutes,
                'created_by' => $actor->getKey(),
            ]);

            foreach ($template->stages()->with(['links', 'meetings', 'tasks', 'quiz.questions.options'])->get() as $stage) {
                $this->copyStage($stage, $copy);
            }

            return $copy;
        });
    }

    private function copyStage(OnboardingStage $stage, OnboardingTemplate $into): void
    {
        $copy = $into->stages()->create([
            'position' => $stage->position,
            'is_final_exam' => $stage->is_final_exam,
            'responsible_ids' => $stage->responsible_ids,
            'day_offset' => $stage->day_offset,
            'title' => $stage->title,
            'description' => $stage->description,
            'achievement_id' => $stage->achievement_id,
            'quiz_achievement_id' => $stage->quiz_achievement_id,
        ]);

        foreach ($stage->links as $link) {
            $copy->links()->create([
                'linkable_type' => $link->linkable_type,
                'linkable_id' => $link->linkable_id,
                'position' => $link->position,
                'is_required' => $link->is_required,
                'day_offset' => $link->day_offset,
                'achievement_id' => $link->achievement_id,
            ]);
        }

        foreach ($stage->meetings as $meeting) {
            $copy->meetings()->create([
                'position' => $meeting->position,
                'topic' => $meeting->topic,
                'format' => $meeting->format,
                'place' => $meeting->place,
                'with_user_id' => $meeting->with_user_id,
                'with_role' => $meeting->with_role,
                'achievement_id' => $meeting->achievement_id,
            ]);
        }

        foreach ($stage->tasks as $task) {
            $copy->tasks()->create([
                'position' => $task->position,
                'text' => $task->text,
                'confirm_by' => $task->confirm_by,
                'responsible_ids' => $task->responsible_ids ?? [],
                'is_required' => $task->is_required,
                'achievement_id' => $task->achievement_id,
            ]);
        }

        $quiz = $stage->quiz;

        if ($quiz === null) {
            return;
        }

        // Вопросы без номеров: у копии они свои, и история попыток оригинала
        // к ней не относится.
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
}
