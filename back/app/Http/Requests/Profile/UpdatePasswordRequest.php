<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Support\Authorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class UpdatePasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => [
                'required', 'string',
                // The guard is named rather than left to the default: by the
                // time this runs, `auth:sanctum` has rewritten the default to
                // "sanctum" for the rest of the request, and the one thing
                // standing between a stranger and this account should not
                // depend on which guard happens to be in that slot. See
                // App\Support\Authorization.
                'current_password:'.Authorization::GUARD,
            ],
            'password' => [
                'required', 'string', 'confirmed', 'different:current_password',
                Password::defaults(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Введите текущий пароль.',
            'current_password.current_password' => 'Текущий пароль неверен.',
            'password.required' => 'Придумайте новый пароль.',
            'password.confirmed' => 'Пароли не совпадают.',
            'password.different' => 'Новый пароль должен отличаться от текущего.',
        ];
    }
}
