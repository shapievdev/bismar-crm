<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Enums\AiAuthScheme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveSettingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'model' => ['nullable', 'string', 'max:120'],

            // Пусто — поиск остаётся словесным. Это рабочий режим, а не ошибка.
            'embedding_model' => ['nullable', 'string', 'max:120'],

            // Адрес проверяется как URL, потому что опечатка здесь выглядит
            // потом как «консультант недоступен» без единой подсказки почему.
            'base_url' => ['nullable', 'string', 'url', 'max:255'],

            // Пустое значение допустимо и означает «оставить прежний ключ».
            'api_key' => ['nullable', 'string', 'max:255'],

            'auth_scheme' => ['required', Rule::enum(AiAuthScheme::class)],
            'max_tokens' => ['nullable', 'integer', 'min:256', 'max:8192'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'model' => $this->filled('model') ? trim((string) $this->input('model')) : null,
            'embedding_model' => $this->filled('embedding_model') ? trim((string) $this->input('embedding_model')) : null,
            'base_url' => $this->filled('base_url') ? trim((string) $this->input('base_url')) : null,
            'api_key' => $this->input('api_key'),
            'auth_scheme' => $this->input('auth_scheme'),
            'max_tokens' => $this->filled('max_tokens') ? (int) $this->input('max_tokens') : null,
        ];
    }
}
