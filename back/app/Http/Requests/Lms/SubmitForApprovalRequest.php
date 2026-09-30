<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Отправка материала на согласование: кого просят посмотреть.
 *
 * Хотя бы один человек обязателен: отправка без адресата — это «выложить сам», и
 * для этого есть кнопка публикации. Кто именно годится в согласующие, проверяет
 * действие (SubmitForApproval): оно же вычитает автора и уволенных, и отвечать
 * на «а если в списке остался один автор» разбору запроса нечем.
 */
final class SubmitForApprovalRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'approvers' => ['required', 'array', 'min:1'],
            'approvers.*' => ['integer', Rule::exists('users', 'id')],
        ];
    }

    /**
     * @return list<int>
     */
    public function approvers(): array
    {
        /** @var list<int|string> $values */
        $values = $this->validated('approvers');

        return array_values(array_unique(array_map(intval(...), $values)));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'approvers.required' => 'Выберите, кто согласует материал.',
            'approvers.min' => 'Выберите, кто согласует материал.',
        ];
    }
}
