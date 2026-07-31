<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Data\Auth\RegisterData;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class.',email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    public function toData(): RegisterData
    {
        /** @var array{name: string, email: string, password: string} $validated */
        $validated = $this->validated();

        return RegisterData::fromArray($validated);
    }
}
