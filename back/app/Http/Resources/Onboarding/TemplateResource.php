<?php

declare(strict_types=1);

namespace App\Http\Resources\Onboarding;

use App\Http\Resources\AchievementResource;
use App\Http\Resources\Lms\QuizResource;
use App\Models\OnboardingStage;
use App\Models\OnboardingTemplate;
use App\Models\User;
use App\Support\News\LinkedMaterial;
use App\Support\Onboarding\RouteSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Шаблон маршрута в ответе API — ведущему адаптацию.
 *
 * Этапы едут только загруженными; ключ теста прячет сам QuizResource по праву
 * onboarding.manage (Quiz::isEditableBy).
 *
 * @mixin OnboardingTemplate
 */
final class TemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'history' => $this->when($this->relationLoaded('stages'), fn () => DB::table('onboarding_activities')->where('template_id', $this->id)->latest('id')->limit(100)->get()->map(function ($entry) {
                $entry->actor_name = User::withTrashed()->find($entry->actor_id)?->name;

                return $entry;
            })),
            'version' => (int) $this->version,
            'parent_id' => $this->parent_id,
            'job_title' => $this->job_title,
            'store' => $this->store,
            'settings' => RouteSettings::of($this->settings),
            'duration_days' => $this->duration_days,
            'day_mode' => $this->day_mode->value,
            'day_mode_label' => $this->day_mode->label(),
            'allow_ahead' => $this->allow_ahead,
            'meeting_reminder_minutes' => $this->meeting_reminder_minutes,
            'author' => $this->whenLoaded('author', fn () => $this->author === null ? null : [
                'id' => $this->author->getKey(),
                'name' => $this->author->name,
            ]),
            'stages_count' => $this->whenCounted('stages'),
            'stages' => $this->whenLoaded('stages', fn () => $this->stages
                ->map(fn (OnboardingStage $stage): array => self::stagePayload($stage))
                ->values()
                ->all()),
        ];
    }

    /**
     * Этап шаблона одной структурой — и в составе шаблона, и ответом на
     * сохранение этапа: расходиться им нельзя.
     *
     * @return array<string, mixed>
     */
    public static function stagePayload(OnboardingStage $stage): array
    {
        return [
            'id' => (int) $stage->getKey(),
            'position' => $stage->position,
            'is_final_exam' => $stage->is_final_exam,
            'responsible_ids' => $stage->responsible_ids ?? [],
            'day_offset' => $stage->day_offset,
            'title' => $stage->title,
            'description' => $stage->description,

            // Значки этапа: за него целиком и за сданный тест. Номер — для
            // формы, значок — чтобы нарисовать его рядом, не ходя в каталог.
            'achievement_id' => $stage->achievement_id,
            'achievement' => AchievementResource::badge($stage->achievement),
            'quiz_achievement_id' => $stage->quiz_achievement_id,
            'quiz_achievement' => AchievementResource::badge($stage->quizAchievement),

            'links' => $stage->links->map(fn ($link): array => [
                'id' => (int) $link->getKey(),
                // Вид — глазами экрана: документ и справочник порознь.
                'type' => $link->linkable_type === 'course'
                    ? 'course'
                    : ($link->linkable?->kind?->value ?? 'document'),
                'material_id' => (int) $link->linkable_id,
                'title' => $link->linkable === null ? 'Материал удалён' : LinkedMaterial::title($link->linkable),
                'url' => $link->linkable === null ? null : LinkedMaterial::url($link->linkable),
                'is_required' => $link->is_required,
                'day_offset' => $link->day_offset,
                'achievement_id' => $link->achievement_id,
                'achievement' => AchievementResource::badge($link->achievement),
            ])->values()->all(),
            'meetings' => $stage->meetings->map(fn ($meeting): array => [
                'id' => (int) $meeting->getKey(),
                'topic' => $meeting->topic,
                'format' => $meeting->format->value,
                'place' => $meeting->place,
                'with_user_id' => $meeting->with_user_id,
                'participant_ids' => $meeting->participantIds(),
                'participants' => $meeting->participantPeople(),
                'scheduled_time' => $meeting->scheduled_time,
                'with_name' => $meeting->counterpart?->name,
                'with_role' => $meeting->with_role,
                'achievement_id' => $meeting->achievement_id,
                'achievement' => AchievementResource::badge($meeting->achievement),
            ])->values()->all(),
            'tasks' => $stage->tasks->map(fn ($task): array => [
                'id' => (int) $task->getKey(),
                'text' => $task->text,
                'confirm_by' => $task->confirm_by->value,
                'responsible_ids' => $task->responsible_ids ?? [],
                'is_required' => $task->is_required,
                'achievement_id' => $task->achievement_id,
                'achievement' => AchievementResource::badge($task->achievement),
            ])->values()->all(),
            'quiz' => $stage->relationLoaded('quiz') && $stage->quiz !== null
                ? QuizResource::make($stage->quiz)->resolve()
                : null,
        ];
    }
}
