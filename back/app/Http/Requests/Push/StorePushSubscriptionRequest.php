<?php

declare(strict_types=1);

namespace App\Http\Requests\Push;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Подписка устройства на уведомления — ровно то, что выдал браузер.
 *
 * Адрес и два ключа приходят как есть: их выдаёт служба доставки, и проверять
 * в них нечего, кроме того, что они непусты.
 */
final class StorePushSubscriptionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:2000', 'url'],
            'public_key' => ['required', 'string', 'max:255'],
            'auth_token' => ['required', 'string', 'max:255'],
            // Чем подписались — чтобы человек узнал своё устройство в списке.
            'device' => ['nullable', 'string', 'max:255'],
        ];
    }
}
