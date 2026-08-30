<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Models\User;
use App\Support\Phone;
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
            // Необязательные — и остаются такими: пустое поле означает «убрать».
            'phone' => ['nullable', 'string', 'regex:'.Phone::PATTERN],
            'job_title' => ['nullable', 'string', 'max:255'],

            // Optional: sent only when the administrator is resetting it.
            'password' => ['nullable', 'string', Password::defaults()],
        ];
    }

    /**
     * Сообщение о номере — своё, по той же причине, что и в StoreUserRequest.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Телефон должен быть российским номером: +7 и десять цифр.',
        ];
    }

    /**
     * Номер приводится к хранимому виду до проверки — см. App\Support\Phone.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => Phone::normalize($this->input('phone'))]);
    }
}
