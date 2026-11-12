<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AwardsAchievement;
use Database\Factories\OnboardingStageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Этап шаблона: день маршрута и его содержимое.
 *
 * Содержимое копируется в личный маршрут при наложении шаблона. Правки
 * программы не меняют задания, встречи и результаты обучающихся.
 * Удаление мягкое: происхождение этапов остаётся доступно для статистики.
 */
#[Fillable([
    'is_final_exam', 'responsible_ids',
    'template_id', 'position', 'day_offset', 'title', 'description',
    'achievement_id', 'quiz_achievement_id',
])]
class OnboardingStage extends Model
{
    /** @use HasFactory<OnboardingStageFactory> */
    use AwardsAchievement, HasFactory, SoftDeletes;

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
            'day_offset' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OnboardingTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class, 'template_id')->withTrashed();
    }

    /**
     * @return HasMany<OnboardingStageLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(OnboardingStageLink::class, 'stage_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * @return HasMany<OnboardingStageMeeting, $this>
     */
    public function meetings(): HasMany
    {
        return $this->hasMany(OnboardingStageMeeting::class, 'stage_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * @return HasMany<OnboardingStageTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingStageTask::class, 'stage_id')
            ->orderBy('position')
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
     * Тест этапа — шестой владелец Quiz (morph `onboarding_stage`).
     *
     * @return MorphOne<Quiz, $this>
     */
    public function quiz(): MorphOne
    {
        return $this->morphOne(Quiz::class, 'quizzable');
    }
}
