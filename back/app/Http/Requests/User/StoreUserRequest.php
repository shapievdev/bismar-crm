<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Data\User\NewUserData;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StoreUserRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            // Not everyone has one, so it is the only part that may be left out.
            'middle_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            // The administrator sets the first password and passes it on; the
            // same strength rules apply as when someone registers themselves.
            'password' => ['required', 'string', Password::defaults()],
        ];
    }

    public function toData(): NewUserData
    {
        /** @var array{last_name: string, first_name: string, middle_name?: string|null, email: string, password: string} $validated */
        $validated = $this->validated();

        return NewUserData::fromArray($validated);
    }
}
