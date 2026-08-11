<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retires job-title roles: permissions now hang off the person.
 *
 * There are only two standings left — superadmin and administrator — and both
 * carry everything by way of Gate::before rather than by explicit grants.
 * Everyone else keeps exactly what their old role gave them, copied onto them
 * directly, so nobody loses access on the way through.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->copyRoleGrantsToTheirHolders();
        $this->removeJobTitleRoles();
        $this->clearGrantsOnStandings();

        Schema::dropIfExists('permission_templates');
    }

    /**
     * Irreversible by design: the roles this dropped carried names invented at
     * runtime, and nothing records which permission came from which one.
     */
    public function down(): void
    {
        Schema::create('permission_templates', function ($table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('description', 500)->nullable();
            $table->jsonb('permissions');
            $table->timestamps();
        });
    }

    private function copyRoleGrantsToTheirHolders(): void
    {
        $retiredRoleIds = DB::table('roles')
            ->whereNotIn('name', AccessLevel::storedValues())
            ->pluck('id');

        if ($retiredRoleIds->isEmpty()) {
            return;
        }

        // Permission ids per retired role.
        $permissionsByRole = DB::table('role_has_permissions')
            ->whereIn('role_id', $retiredRoleIds)
            ->get()
            ->groupBy('role_id')
            ->map(fn ($grants) => $grants->pluck('permission_id')->all());

        $holders = DB::table('model_has_roles')
            ->whereIn('role_id', $retiredRoleIds)
            ->where('model_type', User::class)
            ->get();

        $rows = [];

        foreach ($holders as $holder) {
            foreach ($permissionsByRole->get($holder->role_id, []) as $permissionId) {
                // Keyed so two roles granting the same permission make one row.
                $rows["{$holder->model_id}:{$permissionId}"] = [
                    'permission_id' => $permissionId,
                    'model_type' => User::class,
                    'model_id' => $holder->model_id,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('model_has_permissions')->insertOrIgnore(array_values($rows));
        }
    }

    private function removeJobTitleRoles(): void
    {
        $ids = DB::table('roles')->whereNotIn('name', AccessLevel::storedValues())->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('model_has_roles')->whereIn('role_id', $ids)->delete();
        DB::table('role_has_permissions')->whereIn('role_id', $ids)->delete();
        DB::table('roles')->whereIn('id', $ids)->delete();
    }

    /**
     * The remaining two standings need no grants of their own.
     */
    private function clearGrantsOnStandings(): void
    {
        $ids = DB::table('roles')->whereIn('name', AccessLevel::storedValues())->pluck('id');

        DB::table('role_has_permissions')->whereIn('role_id', $ids)->delete();
    }
};
