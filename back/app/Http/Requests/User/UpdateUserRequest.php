<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Models\User;
use App\Support\Email;
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
            // Почта необязательна — входят по телефону, — и пустое поле здесь
            // значит «убрать»: адрес мог смениться на никакой.
            'email' => [
                'nullable', 'string', 'email', 'max:255',
                // Ignores the edited user, so saving an unchanged address does
                // not collide with their own record.
                Rule::unique(User::class, 'email')->ignore($this->route('user')?->getKey()),
            ],
            // Телефон стёрть нельзя: с него сотрудник входит, и пустое поле
            // заперло бы его снаружи. Уникальность — по той же причине, что и у
            // почты, и с тем же исключением для собственной записи.
            'phone' => [
                'required', 'string', 'regex:'.Phone::PATTERN,
                Rule::unique(User::class, 'phone')->ignore($this->route('user')?->getKey()),
            ],

            // Должность — по-прежнему необязательная: пустое поле значит «убрать».
            'job_title' => ['nullable', 'string', 'max:255'],

            // Optional: sent only when the administrator is resetting it.
            'password' => ['nullable', 'string', Password::defaults()],
        ];
    }

    /**
     * Отказы — те же, что и при заведении, см. StaffFieldMessages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StaffFieldMessages::all();
    }

    /**
     * Номер и почта приводятся к хранимому виду до проверки — см.
     * App\Support\Phone и App\Support\Email.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => Phone::normalize($this->input('phone')),
            'email' => Email::normalize($this->input('email')),
        ]);
    }
}
