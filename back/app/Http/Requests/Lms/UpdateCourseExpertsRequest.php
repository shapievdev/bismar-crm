<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCourseExpertsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Присутствует всегда: пустой список — это «за курс не отвечает
            // никто», и отличить его от «поле не прислали» иначе нельзя.
            'experts' => ['present', 'array'],
            'experts.*' => ['integer', Rule::exists('users', 'id')],
        ];
    }

    /**
     * @return list<int>
     */
    public function experts(): array
    {
        /** @var list<int> $experts */
        $experts = $this->validated('experts', []);

        return array_values(array_unique(array_map(intval(...), $experts)));
    }
}
