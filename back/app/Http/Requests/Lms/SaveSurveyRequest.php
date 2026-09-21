<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Enums\SurveyQuestionType;
use App\Models\SurveyQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Присланный опрос.
 *
 * Проверяется то, что делает опрос проходимым: у выбора должны быть варианты, у
 * шкалы — осмысленные границы. Ключа и очков здесь нет вовсе — в этом и разница
 * с тестом (см. SaveQuizRequest): правильного ответа у опроса не бывает, и
 * проверять его нечем.
 */
final class SaveSurveyRequest extends FormRequest
{
    /** Сколько делений у шкалы бывает: две — уже да/нет, больше десяти не читают. */
    private const SCALE_MIN_STEPS = 2;

    private const SCALE_MAX_VALUE = 10;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],

            // Обязательный держит зачёт материала — см. MaterialDues.
            'is_required' => ['sometimes', 'boolean'],

            // Анонимный не записывает, кто что ответил.
            'is_anonymous' => ['sometimes', 'boolean'],

            // Срок приёма ответов. Прошедшая дата разрешена намеренно: ею
            // закрывают опрос, который пора закрыть.
            'closes_at' => ['nullable', 'date'],

            'thanks' => ['nullable', 'string', 'max:500'],

            'questions' => ['required', 'array', 'min:1'],

            // Номер вопроса — то, чем он остаётся собой при правке: по нему
            // разложены снимки ответов, см. SaveSurvey.
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.text' => ['required', 'string', 'max:2000'],
            'questions.*.type' => ['required', Rule::enum(SurveyQuestionType::class)],

            // Обязателен ли вопрос внутри опроса — не то же, что обязателен ли
            // опрос для материала.
            'questions.*.is_required' => ['sometimes', 'boolean'],

            // «Свой вариант» — только у выбора; у прочих видов молча снимается
            // в SaveSurvey, потому что рисовать его там негде.
            'questions.*.allows_other' => ['sometimes', 'boolean'],

            'questions.*.scale_min' => ['nullable', 'integer', 'min:0', 'max:'.self::SCALE_MAX_VALUE],
            'questions.*.scale_max' => ['nullable', 'integer', 'min:1', 'max:'.self::SCALE_MAX_VALUE],
            'questions.*.scale_min_label' => ['nullable', 'string', 'max:120'],
            'questions.*.scale_max_label' => ['nullable', 'string', 'max:120'],

            'questions.*.options' => ['sometimes', 'array', 'max:30'],
            // Номер варианта хранится в снимке ответа так же, как номер вопроса.
            'questions.*.options.*.id' => ['nullable', 'integer'],
            'questions.*.options.*.text' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * Вопрос, на который нельзя ответить, до базы доходить не должен: выбор без
     * вариантов — пустой список, а шкала от пяти до одного — не шкала.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array{type?: string, options?: array<int, mixed>, scale_min?: ?int, scale_max?: ?int}> $questions */
            $questions = $this->input('questions', []);

            foreach ($questions as $index => $question) {
                $type = SurveyQuestionType::tryFrom((string) ($question['type'] ?? ''));

                if ($type === null) {
                    continue;
                }

                if ($type->isChoice()) {
                    $options = is_array($question['options'] ?? null) ? $question['options'] : [];

                    // Один вариант — тоже не вопрос: отметить его можно только
                    // одним способом, и спрашивать об этом незачем.
                    if (count($options) < 2) {
                        $validator->errors()->add(
                            "questions.{$index}.options",
                            'Добавьте хотя бы два варианта — из одного не выбирают.',
                        );
                    }
                }

                if ($type->isScale()) {
                    $from = $question['scale_min'] ?? SurveyQuestion::DEFAULT_SCALE_MIN;
                    $to = $question['scale_max'] ?? SurveyQuestion::DEFAULT_SCALE_MAX;

                    if ((int) $to - (int) $from + 1 < self::SCALE_MIN_STEPS) {
                        $validator->errors()->add(
                            "questions.{$index}.scale_max",
                            'У шкалы должно быть хотя бы два деления.',
                        );
                    }
                }
            }
        });
    }
}
