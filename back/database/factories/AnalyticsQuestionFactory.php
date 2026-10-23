<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnalyticsAnswerOutcome;
use App\Models\AnalyticsQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsQuestion>
 */
final class AnalyticsQuestionFactory extends Factory
{
    protected $model = AnalyticsQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'question' => 'Как идут продажи?',
            'answer' => 'Выручка за месяц выросла.',
            'dashboard' => null,
            'outcome' => AnalyticsAnswerOutcome::Explained,
            'model' => 'gpt-4o-mini',
            'duration_ms' => 1200,
        ];
    }

    /** Ответ, к которому собран дашборд. */
    public function composed(): self
    {
        return $this->state(fn (): array => [
            'outcome' => AnalyticsAnswerOutcome::Composed,
            'dashboard' => [
                'title' => 'Продажи за месяц',
                'slice' => ['period' => ['preset' => 'month']],
                'widgets' => [
                    ['type' => 'kpi', 'metric' => 'revenue', 'span' => 3],
                    ['type' => 'bars', 'dimension' => 'warehouse', 'metric' => 'revenue', 'span' => 6],
                ],
            ],
        ]);
    }

    /**
     * Сбой: такие записи не попадают ни в переписку, ни в память модели.
     */
    public function failed(): self
    {
        return $this->state(fn (): array => [
            'outcome' => AnalyticsAnswerOutcome::Failed,
            'answer' => 'Не удалось спросить модель.',
            'dashboard' => null,
        ]);
    }

    /** Очищенное сотрудником — его не помнят и не показывают. */
    public function forgotten(): self
    {
        return $this->state(fn (): array => ['hidden_at' => now()]);
    }
}
