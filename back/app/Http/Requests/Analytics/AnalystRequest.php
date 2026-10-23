<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Вопрос к ИИ-аналитику.
 *
 * Предел длины, и только он: спрашивать можно о чём угодно. Что из вопроса
 * выйдет, решает словарь — а короткое «а по неделям?» здесь такой же законный
 * вопрос, как длинный, потому что раздел — разговор, и предыдущий круг модель
 * помнит.
 *
 * Право проверяется на маршруте (`analytics.view`), поэтому здесь только разбор.
 */
final class AnalystRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:2', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'question.required' => 'Напишите вопрос.',
            'question.min' => 'Слишком коротко — непонятно, о чём спрашивают.',
            'question.max' => 'Вопрос длиннее тысячи знаков. Спросите короче.',
        ];
    }

    public function question(): string
    {
        return trim((string) $this->validated('question'));
    }
}
