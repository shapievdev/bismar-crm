<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\QuizFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['lesson_id', 'title', 'description', 'passing_score', 'max_attempts'])]
class Quiz extends Model
{
    /** @use HasFactory<QuizFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return HasMany<QuizQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<QuizAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Total points on offer. Zero when the quiz has no questions yet, which
     * callers must treat as "not scorable" rather than dividing by it.
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
