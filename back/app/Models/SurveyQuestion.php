<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SurveyQuestionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Вопрос опроса.
 *
 * Ни очков, ни верного варианта: и то и другое есть у теста, а здесь считать
 * нечего. Обязательность — своя, внутри опроса: «оцените урок» пропустить
 * нельзя, «сколько вам лет» можно. С обязательностью самого опроса для материала
 * она не связана никак, см. Survey::$is_required.
 */
#[Fillable([
    'survey_id', 'text', 'type', 'is_required', 'allows_other',
    'scale_min', 'scale_max', 'scale_min_label', 'scale_max_label', 'position',
])]
class SurveyQuestion extends Model
{
    /** Шкала по умолчанию: от одного до пяти — привычнее любой другой. */
    public const DEFAULT_SCALE_MIN = 1;

    public const DEFAULT_SCALE_MAX = 5;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SurveyQuestionType::class,
            'is_required' => 'boolean',
            'allows_other' => 'boolean',
            'scale_min' => 'integer',
            'scale_max' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Survey, $this>
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    /**
     * @return HasMany<SurveyOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(SurveyOption::class, 'question_id')->orderBy('position')->orderBy('id');
    }

    /**
     * Деления шкалы — от меньшего к большему.
     *
     * Считается здесь, а не на экране: делений должно быть поровну и в форме, и
     * в сводке, и в проверке присланного, иначе ответ «7» пройдёт по шкале до
     * пяти.
     *
     * @return list<int>
     */
    public function scaleSteps(): array
    {
        if (! $this->type->isScale()) {
            return [];
        }

        $from = $this->scale_min ?? self::DEFAULT_SCALE_MIN;
        $to = $this->scale_max ?? self::DEFAULT_SCALE_MAX;

        return $to < $from ? [] : range($from, $to);
    }
}
