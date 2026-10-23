<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Новый пароль по ссылке из письма.
 *
 * Номер сотрудника и токен приезжают из ссылки, а не набираются: форма
 * показывает только два поля пароля. Проверяются они всё равно здесь — ссылку
 * правят руками в адресной строке не реже, чем по ней переходят.
 *
 * Правила пароля те же, что при смене его в профиле (см.
 * Profile\UpdatePasswordRequest): пароль, поставленный по забывчивости, не
 * должен быть слабее поставленного осознанно. Чего здесь нет — требования
 * отличаться от прежнего: прежнего человек не знает, за этим он и пришёл.
 */
final class ResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user' => ['required', 'integer'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user.required' => 'Ссылка неполная: откройте её из письма целиком.',
            'token.required' => 'Ссылка неполная: откройте её из письма целиком.',
            'password.required' => 'Придумайте новый пароль.',
            'password.confirmed' => 'Пароли не совпадают.',
        ];
    }
}
