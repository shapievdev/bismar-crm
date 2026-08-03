<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
        ];
    }

    /**
     * A category cannot sit under itself or under one of its own descendants —
     * that would detach the whole branch from the tree and make it unreachable.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $category = $this->route('category');
            $parentId = $this->input('parent_id');

            if (! $category instanceof Category || $parentId === null) {
                return;
            }

            if ($category->wouldCycleUnder((int) $parentId)) {
                $validator->errors()->add(
                    'parent_id',
                    'Категорию нельзя вложить в себя или в свою подкатегорию.',
                );
            }
        });
    }
}
