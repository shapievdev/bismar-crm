<?php

declare(strict_types=1);

namespace App\Actions\News;

use App\Enums\NewsAcknowledgementSource;
use App\Exceptions\ConflictException;
use App\Models\NewsQuiz;
use App\Models\NewsQuizAttempt;
use App\Models\NewsQuizQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Проверяет попытку и, если сдал, засчитывает ознакомление.
 *
 * Тест здесь и есть подтверждение (решение пользователя 2026-08-27): сдал —
 * значит прочитал, отдельной кнопки при тесте не показывают.
 */
final readonly class GradeNewsQuizAttempt
{
    public function __construct(private AcknowledgeNews $acknowledge) {}

    /**
     * @param  array<int, list<int>>  $answers  Номер вопроса => выбранные варианты.
     *
     * @throws ConflictException
     */
    public function handle(NewsQuiz $quiz, User $reader, array $answers): NewsQuizAttempt
    {
        $quiz->loadMissing('questions.options', 'news');

        if ($quiz->questions->isEmpty()) {
            throw new ConflictException('В проверке пока нет вопросов.');
        }

        if (! $quiz->hasAttemptsLeft($reader)) {
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

        return DB::transaction(function () use ($quiz, $reader, $answers, $score, $passed): NewsQuizAttempt {
            $attempt = NewsQuizAttempt::create([
                'quiz_id' => $quiz->getKey(),
                'user_id' => $reader->getKey(),
                'score' => $score,
                'passed' => $passed,
                'answers' => $answers,
                'completed_at' => now(),
            ]);

            if ($passed && $quiz->news !== null) {
                $this->acknowledge->handle($quiz->news, $reader, NewsAcknowledgementSource::Quiz);
            }

            return $attempt;
        });
    }

    /**
     * Вопрос засчитан, только когда выбранное совпало с верным в точности:
     * частичный зачёт позволил бы отметить все варианты и пройти.
     *
     * @param  list<int>  $selectedOptionIds
     */
    private function isAnsweredCorrectly(NewsQuizQuestion $question, array $selectedOptionIds): bool
    {
        $correct = $question->correctOptionIds()->map(intval(...))->sort()->values()->all();
        $selected = collect($selectedOptionIds)->map(intval(...))->unique()->sort()->values()->all();

        return $correct === $selected && $correct !== [];
    }
}
