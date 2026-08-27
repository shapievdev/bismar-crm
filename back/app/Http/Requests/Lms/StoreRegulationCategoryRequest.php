<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Models\RegulationCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreRegulationCategoryRequest extends FormRequest
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
            'parent_id' => ['nullable', 'integer', Rule::exists('regulation_categories', 'id')],
        ];
    }

    /**
     * Категория не может стоять под собой или под собственным потомком — так
     * ветка отрывается от дерева и становится недостижимой.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $category = $this->route('category');
            $parentId = $this->input('parent_id');

            if (! $category instanceof RegulationCategory || $parentId === null) {
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
