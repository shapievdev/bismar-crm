<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Один шаг в плане обучения сотрудника: что пройти и в каком порядке.
 *
 * Шаг — курс или регламент (2026-08-27). Связь полиморфная, а не две колонки:
 * держать под каждый вид свою значит спрашивать «а если завтра третий» на
 * каждом запросе.
 *
 * Порядок здесь — совет, а не запрет: сотрудник волен открыть любой шаг, а
 * номер говорит, в каком порядке их задумывали (решение пользователя
 * 2026-08-27). Ничего похожего на блокировку в модели нет намеренно — появись
 * она потом, ей место в политике курса, а не здесь.
 */
#[Fillable(['user_id', 'plannable_type', 'plannable_id', 'position', 'assigned_by_id'])]
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
     * Что назначено — курс или регламент.
     *
     * @return MorphTo<Model, $this>
     */
    public function plannable(): MorphTo
    {
        return $this->morphTo();
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
