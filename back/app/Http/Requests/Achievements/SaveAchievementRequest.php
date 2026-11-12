<?php

declare(strict_types=1);

namespace App\Http\Requests\Achievements;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveAchievementRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => [
                'required', 'string', 'max:255',
                // Имя спрятанного значка тоже занято: иначе в профилях
                // оказались бы два «Первых выхода в зал» с разной картинкой.
                Rule::unique('achievements', 'title')->ignore($this->route('achievement')),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.unique' => 'Достижение с таким названием уже есть.',
        ];
    }
}
