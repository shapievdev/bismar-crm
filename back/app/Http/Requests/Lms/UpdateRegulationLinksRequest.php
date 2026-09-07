<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Список соседей документа целиком.
 */
final class UpdateRegulationLinksRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Присутствует всегда: пустой список — это «убрать всех соседей», и
            // отличить его от «поле не прислали» иначе нельзя.
            'documents' => ['present', 'array'],
            'documents.*' => ['integer', Rule::exists('regulations', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return list<int>
     */
    public function documents(): array
    {
        /** @var list<int> $documents */
        $documents = $this->validated('documents', []);

        return array_values(array_unique(array_map(intval(...), $documents)));
    }
}
