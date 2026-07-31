<?php

declare(strict_types=1);

namespace App\Http\Requests\Role;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreRoleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_.-]*$/',
                Rule::unique('roles', 'name'),
            ],
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::enum(Permission::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'Идентификатор роли может содержать только строчные латинские буквы, цифры, точку, дефис и подчёркивание.',
        ];
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        /** @var list<string> $permissions */
        $permissions = $this->validated('permissions', []);

        return $permissions;
    }
}
