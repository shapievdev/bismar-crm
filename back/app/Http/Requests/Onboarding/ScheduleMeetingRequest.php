<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ScheduleMeetingRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'date'],
            'place' => ['nullable', 'string', 'max:255'],
            'participant_ids' => ['sometimes', 'array', 'max:30'],
            'participant_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNull('dismissed_at')->whereNull('deleted_at')],
            'with_user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')->whereNull('dismissed_at'),
            ],
        ];
    }
}
