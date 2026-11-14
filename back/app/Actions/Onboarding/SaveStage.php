<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\OnboardingStage;
use App\Models\OnboardingStageMeeting;
use App\Models\OnboardingTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Этап шаблона целиком: шапка, материалы, встречи, задания.
 *
 * Редактор присылает этап весь — как тест в SaveQuiz. Строки со своим номером
 * правятся на месте: заготовка встречи — родитель назначенных по ней встреч
 * журналов, и пересозданная рвала бы родословную. Убранное удаляется.
 *
 * Тест этапа сюда не входит — его ведёт SaveQuiz своим адресом: редактор
 * тестов один на всё приложение.
 */
final readonly class SaveStage
{
    /**
     * @param  array{
     *     id?: ?int,
     *     title: string,
     *     description?: ?string,
     *     day_offset: int,
     *     position?: ?int,
     *     achievement_id?: ?int,
     *     quiz_achievement_id?: ?int,
     *     links?: list<array{type: string, id: int, is_required?: bool, day_offset?: ?int, achievement_id?: ?int}>,
     *     meetings?: list<array{id?: ?int, topic: string, format: string, place?: ?string, with_user_id?: ?int, with_role?: ?string, achievement_id?: ?int}>,
     *     tasks?: list<array{id?: ?int, text: string, confirm_by: string, is_required?: bool, achievement_id?: ?int}>,
     * } $attributes
     */
    public function handle(OnboardingTemplate $template, array $attributes): OnboardingStage
    {
        return DB::transaction(function () use ($template, $attributes): OnboardingStage {
            $stage = $this->stageOf($template, $attributes['id'] ?? null);

            $stage->fill([
                'title' => $attributes['title'],
                'is_final_exam' => $attributes['is_final_exam'] ?? false,
                'responsible_ids' => $attributes['responsible_ids'] ?? [],
                'description' => $attributes['description'] ?? null,
                'day_offset' => $attributes['day_offset'],

                // Значки: за этап целиком и за сданный тест. Присланное пустым
                // снимает значок — редактор шлёт этап весь.
                'achievement_id' => $attributes['achievement_id'] ?? null,
                'quiz_achievement_id' => $attributes['quiz_achievement_id'] ?? null,
                'position' => $attributes['position']
                    ?? $stage->position
                    ?? ((int) $template->stages()->max('position') + 1),
            ])->save();

            $this->syncLinks($stage, $attributes['links'] ?? []);
            $this->syncMeetings($stage, $attributes['meetings'] ?? []);
            $this->syncTasks($stage, $attributes['tasks'] ?? []);

            return $stage->load(
                'links.linkable', 'links.achievement',
                'meetings.counterpart', 'meetings.achievement',
                'tasks', 'tasks.achievement',
                'quiz.questions.options',
                'achievement', 'quizAchievement',
            );
        });
    }

    private function stageOf(OnboardingTemplate $template, ?int $id): OnboardingStage
    {
        if ($id === null) {
            return new OnboardingStage(['template_id' => $template->getKey()]);
        }

        // Свой ли этап — у шаблона, а не у базы: чужой номер заводит 404.
        return $template->stages()->whereKey($id)->firstOrFail();
    }

    /**
     * Материалы — списком целиком, порядок присланного и есть порядок.
     *
     * @param  list<array{type: string, id: int, is_required?: bool, day_offset?: ?int, achievement_id?: ?int}>  $links
     */
    private function syncLinks(OnboardingStage $stage, array $links): void
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
     * Встречи: со своим номером — правка на месте, без номера — новая,
     * убранные удаляются.
     *
     * @param  list<array{id?: ?int, topic: string, format: string, place?: ?string, with_user_id?: ?int, with_role?: ?string, achievement_id?: ?int}>  $meetings
     */
    private function syncMeetings(OnboardingStage $stage, array $meetings): void
    {
        $kept = [];

        foreach (array_values($meetings) as $position => $data) {
            $meeting = isset($data['id'])
                ? $stage->meetings()->whereKey($data['id'])->first() ?? new OnboardingStageMeeting(['stage_id' => $stage->getKey()])
                : new OnboardingStageMeeting(['stage_id' => $stage->getKey()]);

            // С кем — ровно одно из двух: роль обнуляет человека и наоборот.
            $withRole = $data['with_role'] ?? null;

            $meeting->fill([
                'position' => $position + 1,
                'topic' => $data['topic'],
                'format' => $data['format'],
                'place' => $data['place'] ?? null,
                'with_user_id' => $withRole === null ? ($data['with_user_id'] ?? null) : null,
                'with_role' => $withRole,
                'achievement_id' => $data['achievement_id'] ?? null,
            ])->save();

            $kept[] = $meeting->getKey();
        }

        $stage->meetings()->whereNotIn('id', $kept)->delete();
    }

    /**
     * @param  list<array{id?: ?int, text: string, confirm_by: string, is_required?: bool, achievement_id?: ?int}>  $tasks
     */
    private function syncTasks(OnboardingStage $stage, array $tasks): void
    {
        $kept = [];

        foreach (array_values($tasks) as $position => $data) {
            $task = isset($data['id'])
                ? $stage->tasks()->whereKey($data['id'])->first()
                : null;

            $task ??= $stage->tasks()->make();

            $task->fill([
                'position' => $position + 1,
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
