<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Enums\Permission;
use App\Models\Quiz;
use App\Models\QuizQuestion;
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

            // Кто выносит приговор. Сотруднику это тоже видно, и намеренно: он
            // должен понимать, ответят ему сразу или работа уйдёт человеку.
            'kind' => $this->kind->value,
            'examiner' => $this->examiner_id === null ? null : [
                'id' => $this->examiner_id,
                'name' => $this->whenLoaded('examiner', fn () => $this->examiner?->name),
            ],
            'questions' => $this->whenLoaded('questions', fn () => $this->questions->map(
                fn ($question): array => [
                    'id' => $question->id,
                    'text' => $question->text,
                    'type' => $question->type->value,
                    'points' => $question->points,
                    'position' => $question->position,

                    // Эталон письменного ответа — тот же ключ: сотруднику,
                    // который сидит над тестом, его видеть нельзя.
                    'expected_answer' => $revealAnswers ? $question->expected_answer : null,

                    // Устройство таблицы — это форма, а не ключ: без него
                    // заполнять нечего, поэтому едет всем. А вот ожидаемые
                    // значения ячеек — ключ, и уходят они только правящему.
                    'table' => $this->tableFor($question, $revealAnswers),
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

    /**
     * Форма таблицы для того, кто её смотрит.
     *
     * Столбцы и строки нужны всем — без них заполнять нечего. Ожидаемые
     * значения ячеек вычищаются: это ключ, и сотруднику, который сидит над
     * бланком, видеть его нельзя.
     *
     * @return array<string, mixed>|null
     */
    private function tableFor(QuizQuestion $question, bool $revealAnswers): ?array
    {
        /** @var array<string, mixed>|null $table */
        $table = $question->table_definition;

        if ($table === null || $revealAnswers) {
            return $table;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];

        $table['rows'] = array_map(
            static fn (array $row): array => ['label' => $row['label'] ?? ''],
            $rows,
        );

        return $table;
    }
}
