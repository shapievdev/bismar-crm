<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Role;
use App\Support\Authorization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Projects the Permission and Role enums into the database.
 *
 * Safe to run on every deploy: existing rows are reused and each role's grants
 * are synced, so adding a case to an enum is all it takes to roll a new
 * capability out. Roles created by administrators at runtime are left alone.
 */
final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Stale cached permissions would make the freshly seeded grants invisible.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            $this->seedPermissions();

            // syncPermissions() resolves names against the registrar's cached
            // permission list, which was loaded before the rows above existed.
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->seedRoles();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedPermissions(): void
    {
        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, $this->guardName());
        }
    }

    private function seedRoles(): void
    {
        foreach (RoleEnum::cases() as $role) {
            Role::findOrCreate($role->value, $this->guardName())
                ->syncPermissions($role->permissionValues());
        }
    }

    private function guardName(): string
    {
        return Authorization::GUARD;
    }
}
