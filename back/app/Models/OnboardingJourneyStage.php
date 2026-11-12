<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AwardsAchievement;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Этап личного маршрута — со своим содержимым.
 *
 * Материалы, встречи, задания и тест лежат при нём, а не читаются с шаблона:
 * иначе «поправить человеку его этап» означало бы поправить всем сразу. С
 * трафарета этап снимают копией, и `source_stage_id` остаётся родословной —
 * по ней сводится статистика и считается рекорд по одному и тому же тесту.
 *
 * Статус не хранится: он выводится из фактов — см. App\Support\Onboarding\
 * StageRequirements.
 */
#[Fillable([
    'is_final_exam', 'responsible_ids', 'due_at', 'opening_override_at', 'notified_at', 'template_id', 'template_version', 'template_title',
    'journey_id', 'source_stage_id', 'position', 'title', 'description',
    'day_offset', 'opens_at', 'completed_at', 'extra_attempts',
    'achievement_id', 'quiz_achievement_id',
])]
class OnboardingJourneyStage extends Model
{
    use AwardsAchievement;

    protected $attributes = ['is_final_exam' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_final_exam' => 'boolean',
            'responsible_ids' => 'array',
            'position' => 'integer',
            'due_at' => 'datetime',
            'opening_override_at' => 'datetime',
            'notified_at' => 'datetime',
            'day_offset' => 'integer',
            'opens_at' => 'datetime',
            'completed_at' => 'datetime',
            'extra_attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OnboardingJourney, $this>
     */
    public function journey(): BelongsTo
    {
        return $this->belongsTo(OnboardingJourney::class, 'journey_id');
    }

    /**
     * Трафарет, с которого этап снят. Null — этап придуман для человека.
     *
     * @return BelongsTo<OnboardingStage, $this>
     */
    public function sourceStage(): BelongsTo
    {
        return $this->belongsTo(OnboardingStage::class, 'source_stage_id')->withTrashed();
    }

    /**
     * @return HasMany<OnboardingJourneyStageLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(OnboardingJourneyStageLink::class, 'journey_stage_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * @return HasMany<OnboardingJourneyMeeting, $this>
     */
    public function meetings(): HasMany
    {
        return $this->hasMany(OnboardingJourneyMeeting::class, 'journey_stage_id')
            ->orderBy('id');
    }

    /**
     * @return HasMany<OnboardingJourneyTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingJourneyTask::class, 'journey_stage_id')
            ->orderBy('id');
    }

    /**
     * Достижение за сданный тест этапа.
     *
     * Отдельно от достижения за весь этап: тест — самостоятельный шаг внутри
     * него, и за него дают свой значок. Колонка лежит на этапе, а не на строке
     * теста: `quizzes` общая на урок, документ, новость и экзамен, и поле,
     * нужное одному владельцу из шести, ей не принадлежит.
     *
     * @return BelongsTo<Achievement, $this>
     */
    public function quizAchievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class, 'quiz_achievement_id')->withTrashed();
    }

    /**
     * Тест этапа — настоящий, с попытками (morph `onboarding_journey_stage`).
     *
     * @return MorphOne<Quiz, $this>
     */
    public function quiz(): MorphOne
    {
        return $this->morphOne(Quiz::class, 'quizzable');
    }

    /**
     * Открыт ли этап по дате. Пустая дата — расписания нет вовсе, и держит
     * этап только очередь (см. StageRequirements).
     */
    public function isOpenAt(CarbonInterface $moment): bool
    {
        return $this->opens_at === null || ! $moment->lt($this->opens_at);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
