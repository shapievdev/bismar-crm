<?php

declare(strict_types=1);

namespace App\Http\Requests\Structure;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Переименование отдела. Место в дереве меняют переносом, а не этой формой:
 * это разные решения, и приходят они с разных концов интерфейса.
 */
final class RenameDepartmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
