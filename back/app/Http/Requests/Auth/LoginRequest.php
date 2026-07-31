<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Data\Auth\LoginData;
use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): LoginData
    {
        /** @var array{email: string, password: string} $validated */
        $validated = $this->validated();

        return new LoginData(
            email: $validated['email'],
            password: $validated['password'],
            remember: $this->boolean('remember'),
        );
    }
}
