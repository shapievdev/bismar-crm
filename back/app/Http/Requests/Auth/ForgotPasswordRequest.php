<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Просьба выдать ссылку на смену пароля.
 *
 * Одно поле, и в нём либо номер, либо почта: входят по номеру, а ссылка уходит
 * на почту, и какое из двух человек помнит — заранее неизвестно. Что именно
 * прислали, разбирает действие (SendPasswordResetLink), а здесь только предел
 * длины: ни «похоже на телефон», ни «похоже на почту» проверять нельзя — отказ
 * «такой почты нет» рассказал бы постороннему, какие адреса в компании есть.
 */
final class ForgotPasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.required' => 'Введите номер телефона или почту.',
            'login.max' => 'Слишком длинная строка.',
        ];
    }

    public function login(): string
    {
        return trim((string) $this->validated('login'));
    }
}
