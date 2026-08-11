<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccessLevel;
use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use App\Support\Authorization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Projects the Permission enum into the database, and keeps the two standings
 * that exist as rows.
 *
 * Safe to run on every deploy: existing rows are reused, so adding a case to
 * the enum is all it takes to roll a new capability out. There are no job-title
 * roles to sync — permissions are granted to people one by one.
 */
final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Stale cached permissions would make the freshly seeded grants invisible.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            foreach (PermissionEnum::cases() as $permission) {
                Permission::findOrCreate($permission->value, Authorization::GUARD);
            }

            // Superadmin and administrator hold nothing explicitly: they pass
            // every check through Gate::before, and listing grants against them
            // would be rows nothing reads.
            foreach (AccessLevel::stored() as $level) {
                Role::findOrCreate($level->value, Authorization::GUARD);
            }

            $this->removeRetiredPermissions();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Drops permission rows the enum no longer defines, along with whatever
     * they were granted to — a name nothing checks is worse than no row at all.
     */
    private function removeRetiredPermissions(): void
    {
        Permission::query()
            ->whereNotIn('name', PermissionEnum::values())
            ->get()
            ->each(fn (Permission $permission) => $permission->delete());
    }
}
