<?php

declare(strict_types=1);

namespace App\Http\Resources\News;

use App\Enums\Permission;
use App\Models\NewsQuiz;
use App\Models\NewsQuizOption;
use App\Models\NewsQuizQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NewsQuiz
 */
final class NewsQuizResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Правильные ответы уходят только тому, кто тест правит. Читателю их
        // отдавать нельзя: они видны в ответе, что бы ни рисовал экран.
        //
        // Спрашиваем право, а не политику: политике нужна сама новость, а
        // грузить её из каждого варианта ответа — запрос на ровном месте.
        $revealsAnswers = $request->user()?->can(Permission::ManageNews->value) ?? false;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'passing_score' => $this->passing_score,
            'max_attempts' => $this->max_attempts,
            'questions' => $this->whenLoaded(
                'questions',
                fn () => $this->questions->map(fn (NewsQuizQuestion $question): array => [
                    'id' => $question->id,
                    'text' => $question->text,
                    'type' => $question->type->value,
                    'points' => $question->points,
                    'options' => $question->options->map(fn (NewsQuizOption $option): array => array_filter([
                        'id' => $option->id,
                        'text' => $option->text,
                        'is_correct' => $revealsAnswers ? $option->is_correct : null,
                    ], static fn (mixed $value): bool => $value !== null))->values(),
                ])->values(),
            ),
        ];
    }
}
