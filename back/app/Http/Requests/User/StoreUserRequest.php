<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Data\User\NewUserData;
use App\Models\User;
use App\Support\Email;
use App\Support\Phone;
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
            // Почта необязательна: входят по телефону, а адрес есть не у
            // каждого. Записанный — по-прежнему указывает на одного человека.
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],

            // Телефон обязателен и уникален: с него сотрудник входит, и запись
            // без номера была бы учётной записью, в которую нельзя попасть.
            'phone' => ['required', 'string', 'regex:'.Phone::PATTERN, Rule::unique(User::class, 'phone')],

            // Должность по-прежнему необязательна: под рукой она бывает не
            // всегда, а завести человека нужно сегодня.
            'job_title' => ['nullable', 'string', 'max:255'],

            // The administrator sets the first password and passes it on; the
            // same strength rules apply as when someone registers themselves.
            'password' => ['required', 'string', Password::defaults()],
        ];
    }

    /**
     * Сообщение о номере пишется здесь: остальные приходят из английского
     * набора фреймворка, а это поле человек чаще всего и набирает неверно.
     *
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
     * Номер приводится к хранимому виду до проверки: пришедшие «8 (999)…» и
     * «+7 999 …» — один и тот же номер, и правилу достаётся уже он.
     *
     * Пустая почта приводится к «нет почты»: пустую строку правило `email`
     * забракует, хотя человек всего лишь ничего не написал.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => Phone::normalize($this->input('phone')),
            'email' => Email::normalize($this->input('email')),
        ]);
    }

    public function toData(): NewUserData
    {
        /** @var array{last_name: string, first_name: string, middle_name?: string|null, email?: string|null, phone: string, job_title?: string|null, password: string} $validated */
        $validated = $this->validated();

        return NewUserData::fromArray($validated);
    }
}
