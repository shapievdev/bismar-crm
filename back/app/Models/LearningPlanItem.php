<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Один шаг в плане обучения сотрудника: курс и его место в очереди.
 *
 * Порядок здесь — совет, а не запрет: сотрудник волен открыть любой шаг, а
 * номер говорит, в каком порядке их задумывали (решение пользователя
 * 2026-08-27). Ничего похожего на блокировку в модели нет намеренно —
 * появись она потом, ей место в политике курса, а не здесь.
 */
#[Fillable(['user_id', 'course_id', 'position', 'assigned_by_id'])]
class LearningPlanItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * Чей это план.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Кто назначил. Пусто, если этого человека уже нет в системе.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    /**
     * План читают только целиком и только по порядку.
     *
     * @param  Builder<LearningPlanItem>  $query
     */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
