<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Data\Auth\LoginData;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Вход по телефону: номер — единственный логин, почта им больше не служит.
 */
final class LoginRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Без `exists`: форма входа не должна отвечать постороннему, заведён
            // такой номер или нет. Неизвестный номер и неверный пароль дают одну
            // и ту же ошибку — её выдаёт AttemptLogin.
            'phone' => ['required', 'string', 'regex:'.Phone::PATTERN],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'Введите номер телефона.',
            'phone.regex' => 'Телефон должен быть российским номером: +7 и десять цифр.',
        ];
    }

    /**
     * Номер приводится к хранимому виду до проверки — см. App\Support\Phone.
     * Набранный «8 (999)…» ищется в базе тем же «+7999…», каким там лежит.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => Phone::normalize($this->input('phone'))]);
    }

    public function toData(): LoginData
    {
        /** @var array{phone: string, password: string} $validated */
        $validated = $this->validated();

        return new LoginData(
            phone: $validated['phone'],
            password: $validated['password'],
            remember: $this->boolean('remember'),
        );
    }
}
