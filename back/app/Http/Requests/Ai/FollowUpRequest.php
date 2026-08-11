<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Заявка на дополнение ответа.
 *
 * Пояснение необязательно: человек, которому ответили не о том, чаще всего не
 * умеет сказать, чего не хватило, — и требовать этого значит не получить самой
 * заявки. Автору хватает и вопроса.
 */
final class FollowUpRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function note(): ?string
    {
        $note = trim((string) $this->validated('note'));

        return $note === '' ? null : $note;
    }
}
