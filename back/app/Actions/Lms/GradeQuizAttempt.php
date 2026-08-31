<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Exceptions\ConflictException;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class GradeQuizAttempt
{
    public function __construct(private CompleteLesson $completeLesson) {}

    /**
     * Grades a submission, records the attempt, and completes the lesson if the
     * learner passed.
     *
     * @param  array<int, list<int>>  $answers  Question id => chosen option ids.
     *
     * @throws ConflictException
     */
    public function handle(Quiz $quiz, User $learner, array $answers, ?Enrollment $enrollment): QuizAttempt
    {
        $quiz->loadMissing('questions.options');

        if ($quiz->questions->isEmpty()) {
            throw new ConflictException('В тесте пока нет вопросов.');
        }

        if (! $quiz->hasAttemptsLeft($learner)) {
            throw new ConflictException('Попытки закончились.');
        }

        $totalPoints = $quiz->totalPoints();
        $earnedPoints = 0;

        foreach ($quiz->questions as $question) {
            if ($this->isAnsweredCorrectly($question, $answers[$question->id] ?? [])) {
                $earnedPoints += $question->points;
            }
        }

        $score = $totalPoints > 0 ? (int) round($earnedPoints / $totalPoints * 100) : 0;
        $passed = $score >= $quiz->passing_score;

        return DB::transaction(function () use ($quiz, $learner, $answers, $score, $passed, $enrollment): QuizAttempt {
            $attempt = QuizAttempt::create([
                'quiz_id' => $quiz->getKey(),
                'user_id' => $learner->getKey(),
                'score' => $score,
                'passed' => $passed,
                'answers' => $answers,
                'completed_at' => now(),
            ]);

            // Сдача теста и есть прохождение урока — если до этого урока дошли
            // по порядку. Непройденные предыдущие попытку не отменяют: она
            // записана, и урок зачтётся, как только очередь дойдёт до него.
            if ($passed && $enrollment !== null && $this->completeLesson->blockedBy($enrollment, $quiz->lesson) === null) {
                $this->completeLesson->handle($enrollment, $quiz->lesson);
            }

            return $attempt;
        });
    }

    /**
     * A question is right only when the chosen options match the correct set
     * exactly — partial credit would let a learner tick every box and pass.
     *
     * @param  list<int>  $selectedOptionIds
     */
    private function isAnsweredCorrectly(QuizQuestion $question, array $selectedOptionIds): bool
    {
        $correct = $question->correctOptionIds()->map(intval(...))->sort()->values()->all();
        $selected = collect($selectedOptionIds)->map(intval(...))->unique()->sort()->values()->all();

        return $correct === $selected && $correct !== [];
    }
}
