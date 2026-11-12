<?php

declare(strict_types=1);

namespace App\Http\Requests\Exams;

use Illuminate\Foundation\Http\FormRequest;

final class SaveExamRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],

            // Окно сдачи. Оба края необязательны и порознь: «с понедельника и
            // навсегда» — законное окно.
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],

            /*
             * Минуты на попытку. Предел — рабочий день: таймер длиннее не
             * мерит ничего, а опечатка «480» вместо «48» не должна подарить
             * кому-то недельную попытку.
             */
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],

            'shuffle_questions' => ['sometimes', 'boolean'],
        ];
    }
}
