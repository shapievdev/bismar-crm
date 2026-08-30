<?php

declare(strict_types=1);

namespace App\Http\Requests\Structure;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Новый отдел: название и то, кому он подчинён.
 *
 * Родитель обязателен: вершина в структуре одна и заведена вместе с базой —
 * второй компании в дереве не бывает.
 */
final class StoreDepartmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['required', 'integer', Rule::exists(Department::class, 'id')],
        ];
    }
}
