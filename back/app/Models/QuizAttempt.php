<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Contracts\PartOfCourse;
use App\Models\Contracts\PartOfRegulation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['quiz_id', 'user_id', 'score', 'passed', 'answers', 'scores', 'completed_at'])]
class QuizAttempt extends Model implements PartOfCourse, PartOfRegulation
{
    /**
     * Тест висит на уроке или на документе, поэтому у попытки два ответа о
     * владельце — и ровно один из них не пустой.
     */
    public function owningCourse(): ?Course
    {
        $owner = $this->owner();

        return $owner instanceof Lesson
            ? $owner->loadMissing('module.course')->module?->course
            : null;
    }

    public function owningRegulation(): ?Regulation
    {
        $owner = $this->owner();

        return $owner instanceof Regulation ? $owner : null;
    }

    private function owner(): ?Model
    {
        return $this->loadMissing('quiz.quizzable')->quiz?->quizzable;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answers' => 'array',
            // Разбор оценки по вопросам: сколько баллов дано и, у письменного
            // ответа, чем измерена схожесть.
            'scores' => 'array',
            'passed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
