<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Версия документа: название, круг групп, закрытость и текст.
 *
 * Группа обязательна хотя бы одна: версия без адресата ничья — она никому не
 * откроется первой, а закрытая не откроется вовсе. Требовать её на входе
 * честнее, чем позволить завести строку, которая ничего не делает.
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

            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['integer', Rule::exists('groups', 'id')],

            // Статья приходит с экрана правки версии, а с формы заведения —
            // нет: там называют версию и выбирают, для кого она.
            'content_json' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'groups.required' => 'Выберите, для кого эта версия.',
            'groups.min' => 'Выберите, для кого эта версия.',
        ];
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
        /** @var list<int|string> $groups */
        $groups = $this->validated('groups', []);

        return array_values(array_unique(array_map(intval(...), $groups)));
    }
}
