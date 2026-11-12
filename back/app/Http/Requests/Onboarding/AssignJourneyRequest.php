<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignJourneyRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->whereNull('dismissed_at'),
            ],

            // День 1. Пусто — сегодня; назначить задним числом можно: стажёр
            // мог выйти в понедельник, а маршрут ему заводят во вторник.
            'started_at' => ['nullable', 'date'],
        ];
    }
}
