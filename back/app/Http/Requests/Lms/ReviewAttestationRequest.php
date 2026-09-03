<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Вердикт проверяющего.
 *
 * Комментарий обязателен при отказе: «не зачтено» без объяснения ничему не учит
 * и кончается перепиской в мессенджере — «а что не так?». При зачёте он
 * необязателен: там и без слов понятно.
 */
final class ReviewAttestationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'is_accepted' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $isAccepted = $this->boolean('is_accepted');

            if (! $isAccepted && trim((string) $this->input('comment')) === '') {
                $validator->errors()->add(
                    'comment',
                    'Напишите, что не так: без этого человек не поймёт, что исправлять.',
                );
            }
        });
    }
}
