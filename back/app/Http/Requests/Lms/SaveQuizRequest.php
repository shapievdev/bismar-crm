<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveQuizRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'passing_score' => ['required', 'integer', 'min:1', 'max:100'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:100'],

            'questions' => ['required', 'array', 'min:1'],
            'questions.*.text' => ['required', 'string', 'max:2000'],
            'questions.*.type' => ['required', Rule::enum(QuestionType::class)],
            'questions.*.points' => ['required', 'integer', 'min:1', 'max:100'],

            'questions.*.options' => ['required', 'array', 'min:2'],
            'questions.*.options.*.text' => ['required', 'string', 'max:1000'],
            'questions.*.options.*.is_correct' => ['required', 'boolean'],
        ];
    }

    /**
     * A question with no correct option can never be answered right, and a
     * single-choice question with several is contradictory. Both would only
     * surface as an unpassable test, so they are rejected at the door.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array{type?: string, options?: array<int, array{is_correct?: bool}>}> $questions */
            $questions = $this->input('questions', []);

            foreach ($questions as $index => $question) {
                $correct = count(array_filter(
                    $question['options'] ?? [],
                    static fn (array $option): bool => (bool) ($option['is_correct'] ?? false),
                ));

                if ($correct === 0) {
                    $validator->errors()->add(
                        "questions.{$index}.options",
                        'Отметьте хотя бы один правильный вариант.',
                    );

                    continue;
                }

                if (($question['type'] ?? null) === QuestionType::Single->value && $correct > 1) {
                    $validator->errors()->add(
                        "questions.{$index}.options",
                        'В вопросе с одним вариантом ответа правильный может быть только один.',
                    );
                }
            }
        });
    }
}
