<?php

declare(strict_types=1);

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Что вводят в форме интеграции с Google.
 *
 * Оба поля необязательны: пустое означает «не задано здесь» — тогда возьмётся
 * значение из переменных окружения, если оно там есть. Проверять их на живость
 * нечем: годны они или нет, знает только Google, и узнаётся это при первом
 * открытии окна выбора файла.
 */
final class SaveGoogleSettingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
