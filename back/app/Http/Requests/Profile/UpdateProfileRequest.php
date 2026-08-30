<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProfileRequest extends FormRequest
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
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Ignores the signed-in user, so saving without changing the
                // address does not collide with their own record.
                Rule::unique(User::class, 'email')->ignore($this->user()?->getKey()),
            ],

            /*
             * Телефон и должность человек ведёт сам: это его способ связи и его
             * место в компании, и ходить за такой правкой к администратору —
             * лишний круг. Пустое поле означает «убрать»: форма присылает
             * запись целиком.
             */
            'phone' => ['nullable', 'string', 'regex:'.Phone::PATTERN],
            'job_title' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
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
