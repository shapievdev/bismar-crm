<?php

declare(strict_types=1);

namespace App\Http\Requests\Achievements;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAchievementImageRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Без SVG — как у аватарки: он умеет нести скрипт, а бакет у всех
            // загруженных файлов один источник.
            'image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.max' => 'Файл слишком большой. Максимум :max КБ.',
            'image.mimes' => 'Подойдёт PNG, JPG или WebP.',
        ];
    }
}
