<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ответ автора на заданный вопрос — строкой таблицы того урока, где он к месту.
 *
 * Вопрос редактируем, а не берётся из журнала как есть: спрашивают вразнобой
 * («а сколько сохнет-то?»), и заносить это в таблицу урока в исходном виде
 * значит засорять её строками, по которым потом ничего не найдётся.
 */
final class ResolveQuestionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lesson_id' => ['required', 'integer', 'exists:lessons,id'],
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string', 'max:5000'],
        ];
    }
}
