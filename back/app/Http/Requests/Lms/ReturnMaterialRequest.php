<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Возврат материала автору: что исправить.
 *
 * Причина обязательна — это единственное, что говорит автору, что делать
 * дальше. Возврат без причины превращает согласование в молчаливый отказ, после
 * которого автор идёт спрашивать вернувшего лично.
 */
final class ReturnMaterialRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'max:2000'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('comment'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comment.required' => 'Напишите, что исправить.',
        ];
    }
}
