<?php

declare(strict_types=1);

namespace App\Actions\Role;

use App\Models\Role;

final readonly class SaveRolePermissions
{
    /**
     * Creates a role, or replaces the grants of an existing one.
     *
     * @param  list<string>  $permissions
     */
    public function create(string $name, array $permissions, string $guardName): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => $guardName]);

        return $this->sync($role, $permissions);
    }

    /**
     * @param  list<string>  $permissions
     */
    public function sync(Role $role, array $permissions): Role
    {
        $role->syncPermissions($permissions);

        return $role->load('permissions');
    }
}
