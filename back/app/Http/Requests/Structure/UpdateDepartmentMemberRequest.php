<?php

declare(strict_types=1);

namespace App\Http\Requests\Structure;

use App\Enums\DepartmentRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Смена роли человека в отделе: тот же человек, другое место.
 */
final class UpdateDepartmentMemberRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(DepartmentRole::class)],
        ];
    }

    public function role(): DepartmentRole
    {
        return DepartmentRole::from((string) $this->validated('role'));
    }
}
