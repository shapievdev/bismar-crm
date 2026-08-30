<?php

declare(strict_types=1);

namespace App\Http\Requests\Structure;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Перенос отдела: кому подчинить и каким по счёту поставить.
 *
 * Кольцо в дереве здесь не ловится: чтобы это проверить, надо знать всю ветку,
 * и ответ на такое — не «поле заполнено неверно», а «так нельзя». Проверяет
 * MoveDepartment.
 */
final class MoveDepartmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['required', 'integer', Rule::exists(Department::class, 'id')],
            'position' => ['required', 'integer', 'min:0'],
        ];
    }
}
