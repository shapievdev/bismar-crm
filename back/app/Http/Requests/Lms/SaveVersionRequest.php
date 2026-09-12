<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Версия документа: название, круг адресатов, закрытость и текст.
 *
 * Адресат — группа, отдел или оба сразу, и хотя бы один обязателен: версия без
 * адресата ничья, она никому не откроется первой, а закрытая не откроется
 * вовсе. Требовать его на входе честнее, чем позволить завести строку, которая
 * ничего не делает.
 */
final class SaveVersionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'is_private' => ['boolean'],

            // Адресат обязателен, но каким он будет — группой, отделом или
            // обоими сразу, — решает автор; поэтому «обязателен» проверяется
            // не полем, а обоими вместе, см. withValidator().
            'groups' => ['present', 'array'],
            'groups.*' => ['integer', Rule::exists('groups', 'id')],

            'departments' => ['present', 'array'],
            'departments.*' => ['integer', Rule::exists('departments', 'id')],

            // Статья приходит с экрана правки версии, а с формы заведения —
            // нет: там называют версию и выбирают, для кого она.
            'content_json' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * Версия без адресата ничья: она никому не откроется первой, а закрытая не
     * откроется вовсе. Но адресатом годится и группа, и отдел, поэтому
     * проверяются они вместе, а не каждое своим `required`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->groups() === [] && $this->departments() === []) {
                $validator->errors()->add('groups', 'Выберите, для кого эта версия.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'is_private' => (bool) $this->boolean('is_private'),
            ...$this->has('content_json') ? ['content_json' => $this->validated('content_json')] : [],
        ];
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
    public function departments(): array
    {
        return $this->numbers('departments');
    }

    /**
     * @return list<int>
     */
    private function numbers(string $key): array
    {
        /** @var list<int|string> $values */
        $values = $this->input($key, []);

        return array_values(array_unique(array_map(intval(...), is_array($values) ? $values : [])));
    }
}
