<?php

declare(strict_types=1);

namespace App\Http\Requests\Group;

use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Группа целиком — и когда её заводят, и когда правят.
 *
 * Имя одно на компанию: две «Наставники» в списке адресатов различить нечем.
 * Своё же имя группе не мешает — при правке она из проверки исключается.
 */
final class SaveGroupRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique(Group::class, 'name')->ignore($this->route('group')),
            ],

            // Зачем группа собрана. Необязательно: у «Кассиров» название
            // говорит всё само.
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Группа с таким названием уже есть.',
        ];
    }

    /**
     * @return array{name: string, description: ?string}
     */
    public function toAttributes(): array
    {
        $description = trim((string) $this->validated('description'));

        return [
            'name' => trim((string) $this->validated('name')),
            // Пустое описание — это отсутствие описания, а не пустая строка:
            // иначе список рисовал бы под названием пустую вторую строку.
            'description' => $description === '' ? null : $description,
        ];
    }
}
