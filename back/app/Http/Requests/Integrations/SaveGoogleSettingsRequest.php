<?php

declare(strict_types=1);

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Что вводят в форме интеграции с Google.
 *
 * Оба поля необязательны: пустое означает «не задано здесь» — тогда возьмётся
 * значение из переменных окружения, если оно там есть. Годны ли они, знает
 * только Google, и узнаётся это при первом открытии окна выбора файла.
 *
 * Одну ошибку проверить всё же необходимо — см. withValidator.
 */
final class SaveGoogleSettingsRequest extends FormRequest
{
    /**
     * Начало секрета OAuth-клиента. Единственное, что здесь по-настоящему
     * нельзя принять: в отличие от ключа API, это тайна.
     */
    private const CLIENT_SECRET = 'GOCSPX-';

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

    /**
     * Секрет клиента в поле ключа API.
     *
     * Ошибка лёгкая: в консоли Google оба значения лежат рядом, у одного
     * клиента, и называются похоже. Цена высокая: сохранённое здесь уезжает в
     * браузер каждому вошедшему — так и задумано, ключ API публичен, — и секрет
     * вместе с ним перестал бы быть секретом. Поэтому не предупреждение, а
     * отказ (случилось 2026-09-03).
     *
     * Проверяется только эта подмена: остальное — забота Google, и запрещать
     * то, чего не понимаем, значит однажды не принять исправный ключ.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $key = trim((string) $this->input('api_key'));

            if (str_starts_with($key, self::CLIENT_SECRET)) {
                $validator->errors()->add(
                    'api_key',
                    'Это секрет OAuth-клиента, а не ключ API: его нельзя показывать сотрудникам, '
                    .'и окно выбора файла им не открыть. Ключ API начинается с «AIza» и заводится '
                    .'отдельно — Credentials → Create credentials → API key. Секрет, если вы уже '
                    .'вводили его здесь, лучше сбросить в консоли Google.',
                );
            }
        });
    }
}
