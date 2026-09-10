<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Data\Auth\RegisterData;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class.',email'],

            // Телефон обязателен и уникален: с него теперь входят, и запись без
            // номера была бы учётной записью, в которую нельзя попасть.
            'phone' => ['required', 'string', 'regex:'.Phone::PATTERN, Rule::unique(User::class, 'phone')],

            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Телефон должен быть российским номером: +7 и десять цифр.',
            'phone.unique' => 'Этот номер уже занят: по нему входит другой сотрудник.',
        ];
    }

    /**
     * Номер приводится к хранимому виду до проверки — см. App\Support\Phone.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => Phone::normalize($this->input('phone'))]);
    }

    public function toData(): RegisterData
    {
        /** @var array{last_name: string, first_name: string, middle_name?: string|null, email: string, phone: string, password: string} $validated */
        $validated = $this->validated();

        return RegisterData::fromArray($validated);
    }
}
