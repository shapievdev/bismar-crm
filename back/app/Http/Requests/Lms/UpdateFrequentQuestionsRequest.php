<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Частые вопросы» документа — списком целиком и в нужном порядке.
 *
 * Повторы здесь не отсеиваются, а порядок сохраняется как пришёл: он и есть
 * ответ на вопрос «с чем приходят чаще». Уникальность оставлена действию — там
 * же, где и правило «сам на себя не ссылается».
 */
final class UpdateFrequentQuestionsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Присутствует всегда: пустой список — это «убрать все вопросы», и
            // отличить его от «поле не прислали» иначе нельзя.
            'documents' => ['present', 'array', 'max:20'],
            'documents.*' => ['integer', Rule::exists('regulations', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'documents.max' => 'В списке частых вопросов не больше двадцати строк — иначе его перестают читать.',
        ];
    }

    /**
     * @return list<int>
     */
    public function documents(): array
    {
        /** @var list<int> $documents */
        $documents = $this->validated('documents', []);

        return array_values(array_map(intval(...), $documents));
    }
}
