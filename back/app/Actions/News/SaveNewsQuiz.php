<?php

declare(strict_types=1);

namespace App\Actions\News;

use App\Models\News;
use App\Models\NewsQuiz;
use Illuminate\Support\Facades\DB;

/**
 * Переписывает проверку к новости целиком.
 *
 * Вопросы и варианты не сверяются по одному, а пересоздаются: редактор шлёт
 * тест полностью, а каскадное удаление не даёт накопиться сиротам. Прошлые
 * попытки это переживают — они хранят отправленные ответы сами.
 */
final readonly class SaveNewsQuiz
{
    /**
     * @param  array{
     *     title: string,
     *     description?: ?string,
     *     passing_score: int,
     *     max_attempts?: ?int,
     *     questions: array<int, array{text: string, type: string, points: int, options: array<int, array{text: string, is_correct: bool}>}>
     * } $attributes
     */
    public function handle(News $news, array $attributes): NewsQuiz
    {
        return DB::transaction(function () use ($news, $attributes): NewsQuiz {
            $quiz = NewsQuiz::updateOrCreate(
                ['news_id' => $news->getKey()],
                [
                    'title' => $attributes['title'],
                    'description' => $attributes['description'] ?? null,
                    'passing_score' => $attributes['passing_score'],
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
