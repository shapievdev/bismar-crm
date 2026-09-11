<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Models\User;
use App\Support\Email;
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
            // Необязательна: логин — телефон, а почта лишь способ связи, и
            // пустое поле значит «нет адреса».
            'email' => [
                'nullable', 'string', 'email', 'max:255',
                // Ignores the signed-in user, so saving without changing the
                // address does not collide with their own record.
                Rule::unique(User::class, 'email')->ignore($this->user()?->getKey()),
            ],

            /*
             * Телефон и должность человек ведёт сам: это его способ связи и его
             * место в компании, и ходить за такой правкой к администратору —
             * лишний круг.
             *
             * Номер при этом стереть нельзя и занять чужой нельзя: с него
             * входят, и пустое поле заперло бы человека снаружи собственной
             * учётной записи.
             */
            'phone' => [
                'required', 'string', 'regex:'.Phone::PATTERN,
                Rule::unique(User::class, 'phone')->ignore($this->user()?->getKey()),
            ],

            // Должность — по-прежнему необязательная: пустое поле значит «убрать».
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
            'phone.unique' => 'Этот номер уже занят: по нему входит другой сотрудник.',
        ];
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
