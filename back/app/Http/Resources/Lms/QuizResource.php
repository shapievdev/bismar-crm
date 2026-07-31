<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Enums\Permission;
use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Quiz
 */
final class QuizResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Which option is correct is the answer key. Sending it to a learner
        // sitting the test would make the test meaningless, so it is exposed
        // only to those who may edit the course.
        $revealAnswers = $request->user()?->can(Permission::UpdateCourses->value) ?? false;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'passing_score' => $this->passing_score,
            'max_attempts' => $this->max_attempts,
            'questions' => $this->whenLoaded('questions', fn () => $this->questions->map(
                fn ($question): array => [
                    'id' => $question->id,
                    'text' => $question->text,
                    'type' => $question->type->value,
                    'points' => $question->points,
                    'position' => $question->position,
                    'options' => $question->options->map(fn ($option): array => array_filter([
                        'id' => $option->id,
                        'text' => $option->text,
                        'position' => $option->position,
                        'is_correct' => $revealAnswers ? $option->is_correct : null,
                    ], static fn ($value): bool => $value !== null))->values(),
                ],
            )->values()),
        ];
    }
}
