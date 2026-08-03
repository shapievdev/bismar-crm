<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAvatarRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // No SVG: it can carry script, and the storage bucket is one origin
            // shared by every uploaded file.
            'avatar' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.max' => 'Файл слишком большой. Максимум :max КБ.',
            'avatar.mimes' => 'Подойдёт PNG, JPG или WebP.',
        ];
    }
}
