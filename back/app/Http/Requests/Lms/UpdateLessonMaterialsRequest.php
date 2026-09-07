<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Документы урока — списком целиком и в нужном порядке.
 *
 * Порядок сохраняется как пришёл: он и есть тот, в каком их читают после
 * статьи. Уникальность и «сам на себя» оставлены действию — там же, где и
 * остальные правила связи.
 */
final class UpdateLessonMaterialsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Присутствует всегда: пустой список — это «убрать все», и отличить
            // его от «поле не прислали» иначе нельзя.
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
            'documents.max' => 'К уроку прикладывают не больше двадцати материалов — дальше их перестают открывать.',
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
