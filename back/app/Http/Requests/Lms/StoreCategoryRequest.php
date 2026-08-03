<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCategoryRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
