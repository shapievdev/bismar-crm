<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserAccessRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'level' => ['required', Rule::enum(AccessLevel::class)],
            // Present even for an administrator, whose ticks are then ignored:
            // the form sends the whole picture, and the action decides.
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::enum(Permission::class)],
        ];
    }

    public function level(): AccessLevel
    {
        return AccessLevel::from((string) $this->validated('level'));
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        /** @var list<string> $permissions */
        $permissions = $this->validated('permissions', []);

        return array_values(array_unique($permissions));
    }
}
