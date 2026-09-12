<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Кого пускают в закрытый материал: люди и группы разом.
 *
 * Отдельно от UpdateCourseAccessRequest, который остался у списков без групп —
 * ответственных за курс и за документ. Ответственный называется поимённо
 * намеренно: это ответ на вопрос «к кому идти», а группа на него не отвечает.
 */
final class UpdateMaterialAccessRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Оба списка присутствуют всегда: пустой — это «убрать всех», и
            // отличить его от «поле не прислали» иначе нечем.
            'members' => ['present', 'array'],
            'members.*' => ['integer', Rule::exists('users', 'id')],

            'groups' => ['present', 'array'],
            'groups.*' => ['integer', Rule::exists('groups', 'id')],
        ];
    }

    /**
     * @return list<int>
     */
    public function members(): array
    {
        return $this->numbers('members');
    }

    /**
     * @return list<int>
     */
    public function groups(): array
    {
        return $this->numbers('groups');
    }

    /**
     * @return list<int>
     */
    private function numbers(string $key): array
    {
        /** @var list<int|string> $values */
        $values = $this->validated($key, []);

        return array_values(array_unique(array_map(intval(...), $values)));
    }
}
