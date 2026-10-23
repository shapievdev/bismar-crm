<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalyticsAnswerOutcome;
use Database\Factories\AnalyticsQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Один вопрос к ИИ-аналитику и что из него вышло.
 *
 * Дашборд хранится описанием, а не цифрами: открыв вчерашний разговор, сотрудник
 * должен увидеть, как дела сейчас. Пересчитывает его исполнитель
 * (App\Support\Analytics\DashboardExecutor) при каждом показе.
 */
#[Fillable([
    'user_id',
    'question',
    'answer',
    'dashboard',
    'outcome',
    'model',
    'duration_ms',
])]
class AnalyticsQuestion extends Model
{
    /** @use HasFactory<AnalyticsQuestionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Переписка этого сотрудника — и только его.
     *
     * Своя у каждого: вопросы одного не должны достраивать вопросы другого,
     * даже если они сидят в одном отделе и спрашивают об одном. Сбои
     * пропускаются — см. AnalyticsAnswerOutcome::Failed.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeConversationOf(Builder $query, User $reader): void
    {
        $query->where('user_id', $reader->getKey())
            ->where('outcome', '!=', AnalyticsAnswerOutcome::Failed->value)
            ->whereNull('hidden_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => AnalyticsAnswerOutcome::class,
            'dashboard' => 'array',
            'hidden_at' => 'datetime',
        ];
    }
}
