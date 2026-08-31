<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Lesson;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;

final readonly class SaveQuiz
{
    /**
     * Replaces a lesson's quiz wholesale.
     *
     * Questions and options are rewritten rather than diffed: the editor sends
     * the complete quiz, and cascading deletes keep orphans from accumulating.
     * Past attempts survive because they store the submitted answers themselves.
     *
     * @param  array{
     *     title: string,
     *     description?: ?string,
     *     max_attempts?: ?int,
     *     questions: array<int, array{text: string, type: string, points: int, options: array<int, array{text: string, is_correct: bool}>}>
     * } $attributes
     */
    public function handle(Lesson $lesson, array $attributes): Quiz
    {
        return DB::transaction(function () use ($lesson, $attributes): Quiz {
            $quiz = Quiz::updateOrCreate(
                ['lesson_id' => $lesson->getKey()],
                [
                    'title' => $attributes['title'],
                    'description' => $attributes['description'] ?? null,
                    // Планку ставит правило, а не присланное: урок зачитывается
                    // при всех верных ответах, и подделать это запросом нельзя.
                    'passing_score' => Quiz::PASSING_SCORE,
                    'max_attempts' => $attributes['max_attempts'] ?? null,
                ],
            );

            $quiz->questions()->delete();

            foreach (array_values($attributes['questions']) as $questionPosition => $questionData) {
                $question = $quiz->questions()->create([
                    'text' => $questionData['text'],
                    'type' => $questionData['type'],
                    'points' => $questionData['points'],
                    'position' => $questionPosition,
                ]);

                foreach (array_values($questionData['options']) as $optionPosition => $optionData) {
                    $question->options()->create([
                        'text' => $optionData['text'],
                        'is_correct' => $optionData['is_correct'],
                        'position' => $optionPosition,
                    ]);
                }
            }

            return $quiz->load('questions.options');
        });
    }
}
