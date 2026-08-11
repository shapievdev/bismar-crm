<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

final class AskRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Long enough for a real question, short enough that the field
            // cannot be used to push a wall of text past the material.
            'question' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function question(): string
    {
        return trim((string) $this->validated('question'));
    }
}
