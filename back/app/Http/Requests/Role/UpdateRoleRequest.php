<?php

declare(strict_types=1);

namespace App\Http\Requests\Role;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Only the permission set is editable. A role's name is an identifier that code
 * and existing assignments rely on, so renaming is deliberately not offered.
 */
final class UpdateRoleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::enum(Permission::class)],
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
