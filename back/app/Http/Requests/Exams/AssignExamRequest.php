<?php

declare(strict_types=1);

namespace App\Http\Requests\Exams;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Кому назначается экзамен. Присланное — полный список: убранный освобождается.
 *
 * Люди, отделы и группы — три способа выбрать, и все три необязательны:
 * пустой выбор снимает назначение со всех.
 */
final class AssignExamRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'users' => ['sometimes', 'array'],
            // Уволенных отсеет само назначение; здесь — только «такой есть».
            'users.*' => ['integer', Rule::exists('users', 'id')],

            'departments' => ['sometimes', 'array'],
            'departments.*' => ['integer', Rule::exists('departments', 'id')],

            'groups' => ['sometimes', 'array'],
            'groups.*' => ['integer', Rule::exists('groups', 'id')],

            // Личный срок. Позже закрытия окна не бывает — обещание сдать
            // после того, как дверь заперли, невыполнимо с самого начала.
            'due_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $exam = $this->route('exam');
            $dueAt = $this->date('due_at');

            if ($exam?->closes_at !== null && $dueAt !== null && $dueAt->gt($exam->closes_at)) {
                $validator->errors()->add('due_at', 'Срок не может быть позже закрытия окна сдачи.');
            }
        });
    }
}
