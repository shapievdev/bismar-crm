<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Enums\QuestionType;
use App\Support\Lms\QuestionTable;
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

            // Номер вопроса — то, чем он остаётся собой при правке: по нему
            // разложены ответы прошлых попыток, см. SaveQuiz. У нового вопроса
            // его нет, чужой не подставить — сверяется с этим же тестом.
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.text' => ['required', 'string', 'max:2000'],
            'questions.*.type' => ['required', Rule::enum(QuestionType::class)],
            'questions.*.points' => ['required', 'integer', 'min:1', 'max:100'],

            // Варианты — только у вопроса с выбором; у письменного их нет
            // вовсе, зато есть эталон. Что чему нужно, разбирает after().
            'questions.*.options' => ['sometimes', 'array'],
            // Номер варианта хранится в ответах попытки так же, как номер
            // вопроса, — и по той же причине приходит обратно.
            'questions.*.options.*.id' => ['nullable', 'integer'],
            'questions.*.options.*.text' => ['required', 'string', 'max:1000'],
            'questions.*.options.*.is_correct' => ['required', 'boolean'],

            'questions.*.expected_answer' => ['nullable', 'string', 'max:4000'],

            // Устройство таблицы: столбцы и строки. Заголовок ведущего столбца
            // пуст — подписей у строк нет вовсе.
            'questions.*.table' => ['sometimes', 'array'],
            'questions.*.table.row_label_title' => ['nullable', 'string', 'max:120'],
            'questions.*.table.can_add_rows' => ['sometimes', 'boolean'],
            'questions.*.table.columns' => ['sometimes', 'array', 'max:12'],
            'questions.*.table.columns.*.title' => ['required', 'string', 'max:120'],
            'questions.*.table.columns.*.kind' => ['required', Rule::in([QuestionTable::TEXT, QuestionTable::SELECT])],
            'questions.*.table.columns.*.options' => ['sometimes', 'array', 'max:20'],
            'questions.*.table.columns.*.options.*' => ['required', 'string', 'max:120'],
            'questions.*.table.rows' => ['sometimes', 'array', 'max:60'],
            'questions.*.table.rows.*.label' => ['nullable', 'string', 'max:120'],

            // Ожидаемые значения по столбцам: пустое — «эту ячейку не сверяем».
            // Без правила они молча отбрасывались бы разбором присланного.
            'questions.*.table.rows.*.expected' => ['sometimes', 'array', 'max:12'],
            'questions.*.table.rows.*.expected.*' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * A question with no correct option can never be answered right, and a
     * single-choice question with several is contradictory. Both would only
     * surface as an unpassable test, so they are rejected at the door.
     *
     * У письменного вопроса проверка другая: вариантов у него нет, а эталон
     * обязателен — без него сравнивать ответ не с чем, и вопрос не взять
     * никогда.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array{type?: string, expected_answer?: ?string, table?: array<string, mixed>, options?: array<int, array{is_correct?: bool}>}> $questions */
            $questions = $this->input('questions', []);

            foreach ($questions as $index => $question) {
                $type = QuestionType::tryFrom((string) ($question['type'] ?? ''));

                if ($type?->isTable() ?? false) {
                    $table = QuestionTable::normalise(
                        is_array($question['table'] ?? null) ? $question['table'] : [],
                    );

                    if ($table === null || $table['columns'] === []) {
                        $validator->errors()->add(
                            "questions.{$index}.table.columns",
                            'Добавьте хотя бы один столбец: без них таблицу нечем заполнять.',
                        );
                    }

                    if ($table !== null && $table['rows'] === [] && ! $table['can_add_rows']) {
                        $validator->errors()->add(
                            "questions.{$index}.table.rows",
                            'Заведите строки или разрешите сотруднику добавлять их.',
                        );
                    }

                    foreach ($table['columns'] ?? [] as $column => $definition) {
                        if ($definition['kind'] === QuestionTable::SELECT && $definition['options'] === []) {
                            $validator->errors()->add(
                                "questions.{$index}.table.columns.{$column}.options",
                                'У столбца с выбором нужны варианты.',
                            );
                        }
                    }

                    continue;
                }

                if ($type?->isWritten() ?? false) {
                    if (trim((string) ($question['expected_answer'] ?? '')) === '') {
                        $validator->errors()->add(
                            "questions.{$index}.expected_answer",
                            'Напишите эталонный ответ — с ним будет сравниваться написанное сотрудником.',
                        );
                    }

                    continue;
                }

                if (count($question['options'] ?? []) < 2) {
                    $validator->errors()->add(
                        "questions.{$index}.options",
                        'В вопросе с выбором нужно хотя бы два варианта.',
                    );

                    continue;
                }

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
