<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use App\Enums\AppealReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Замечание к материалу: кому, с чем и что именно не так.
 *
 * Текст обязателен (требование пользователя 2026-09-06). Одна кнопка без слов
 * оставляет автора с «что-то не так» и ничего не говорит о том, что править;
 * а разговор, начатый пустой репликой, приходится начинать заново вопросом
 * «а что случилось?».
 *
 * Кому писать — проверяет контроллер по самому материалу: список адресатов
 * известен только ему, и «этот человек вообще за материал не отвечает» — не
 * ошибка формы, а отказ.
 */
final class SendAppealRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'recipient_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'reason' => ['required', Rule::enum(AppealReason::class)],
            // Верхняя граница — та же, что у обычной реплики мессенджера: это
            // она и есть, просто написанная с другой страницы.
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Напишите, чего не хватило: без этого автору нечего исправлять.',
        ];
    }

    public function reason(): AppealReason
    {
        return AppealReason::from((string) $this->input('reason'));
    }
}
