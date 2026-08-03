<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCoverRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Images only, and no SVG: it can carry script and would run in the
            // page's origin if ever rendered inline.
            'cover' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cover.max' => 'Обложка слишком большая. Максимум :max КБ.',
            'cover.mimes' => 'Обложка должна быть изображением PNG, JPG или WebP.',
        ];
    }
}
