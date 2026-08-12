<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Новый текст уже сказанной реплики.
 *
 * Вложения правкой не трогаются: приложить файл задним числом — это новое
 * сообщение, а убрать приложенный — удаление. Здесь меняются только слова.
 */
final class EditMessageRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function body(): ?string
    {
        $body = trim((string) $this->validated('body'));

        return $body === '' ? null : $body;
    }
}