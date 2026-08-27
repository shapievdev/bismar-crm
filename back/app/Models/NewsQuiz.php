<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Проверка того, что новость прочитали.
 *
 * Не оценка знаний: сдал — значит ознакомился (решение пользователя
 * 2026-08-27), и никакой кнопки «ознакомлен» при тесте не показывают. Отсюда и
 * разница с тестом урока: разбора ошибок и статистики здесь нет, они нужны
 * тому, кто учит, а не тому, кто оповещает.
 */
#[Fillable(['news_id', 'title', 'description', 'passing_score', 'max_attempts'])]
class NewsQuiz extends Model
{
    protected $table = 'news_quizzes';

    /**
     * @return BelongsTo<News, $this>
     */
    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

    /**
     * @return HasMany<NewsQuizQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(NewsQuizQuestion::class, 'quiz_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<NewsQuizAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(NewsQuizAttempt::class, 'quiz_id');
    }

    /**
     * Сколько очков разыгрывается. Ноль у теста без вопросов — вызывающий
     * обязан считать это «оценить нечем», а не делить на него.
     */
    public function totalPoints(): int
    {
        return (int) $this->questions()->sum('points');
    }

    public function hasAttemptsLeft(User $user): bool
    {
        if ($this->max_attempts === null) {
            return true;
        }

        return $this->attempts()->where('user_id', $user->getKey())->count() < $this->max_attempts;
    }
}
