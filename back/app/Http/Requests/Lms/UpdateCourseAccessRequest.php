<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCourseAccessRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Присутствует всегда: пустой список — это «убрать всех», и
            // отличить его от «поле не прислали» иначе нельзя.
            'members' => ['present', 'array'],
            'members.*' => ['integer', Rule::exists('users', 'id')],
        ];
    }

    /**
     * @return list<int>
     */
    public function members(): array
    {
        /** @var list<int> $members */
        $members = $this->validated('members', []);

        return array_values(array_unique(array_map(intval(...), $members)));
    }
}
