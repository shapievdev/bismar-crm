<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * An administrator correcting a colleague's record.
 *
 * Roles are not here: they have their own endpoint because they carry their own
 * rules about who may grant what.
 */
final class UpdateUserRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Ignores the edited user, so saving an unchanged address does
                // not collide with their own record.
                Rule::unique(User::class, 'email')->ignore($this->route('user')?->getKey()),
            ],
            // Optional: sent only when the administrator is resetting it.
            'password' => ['nullable', 'string', Password::defaults()],
        ];
    }
}
