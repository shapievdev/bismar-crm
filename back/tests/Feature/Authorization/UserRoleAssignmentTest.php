<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\TestCase;

final class UserRoleAssignmentTest extends TestCase
{
    use ActsAsSpaClient, RefreshDatabase;

    /**
     * Regression test for the guard mismatch: `auth:sanctum` rewrites
     * `auth.defaults.guard` mid-request, so a dynamically resolved guard made
     * every non-administrator's permissions invisible. Administrators hid the
     * bug because Gate::before waves them through.
     */
    public function test_a_non_administrator_can_use_a_permission_granted_by_their_role(): void
    {
        $manager = $this->userWithRole(RoleEnum::Manager);

        $this->assertTrue($manager->can(Permission::ViewUsers->value));

        $this->actingAs($manager)
            ->getJson(route('users.index'))
            ->assertOk();
    }

    public function test_a_role_without_the_permission_is_refused(): void
    {
        $this->actingAs($this->userWithRole(RoleEnum::Sales))
            ->getJson(route('users.index'))
            ->assertForbidden();
    }

    public function test_an_administrator_can_change_a_users_roles(): void
    {
        $user = $this->userWithRole(RoleEnum::Viewer);

        $this->actingAs($this->administrator())
            ->putJson(route('users.roles.update', $user), [
                'roles' => [RoleEnum::Sales->value],
            ])
            ->assertOk()
            ->assertJsonPath('data.roles', [RoleEnum::Sales->value]);

        $this->assertTrue($user->fresh()?->hasRole(RoleEnum::Sales->value));
        $this->assertFalse($user->fresh()?->hasRole(RoleEnum::Viewer->value));
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        $user = $this->userWithRole(RoleEnum::Viewer);

        $this->actingAs($this->administrator())
            ->putJson(route('users.roles.update', $user), ['roles' => ['overlord']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roles.0');
    }

    public function test_an_administrator_cannot_strip_their_own_administrator_role(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->putJson(route('users.roles.update', $admin), [
                'roles' => [RoleEnum::Viewer->value],
            ])
            ->assertConflict();

        $this->assertTrue($admin->fresh()?->hasRole(RoleEnum::Admin->value));
    }

    public function test_the_last_administrator_cannot_be_demoted(): void
    {
        $lastAdmin = $this->administrator();
        $manager = $this->userWithRole(RoleEnum::Manager);
        $manager->givePermissionTo(Permission::ManageUsers->value);

        $this->actingAs($manager)
            ->putJson(route('users.roles.update', $lastAdmin), [
                'roles' => [RoleEnum::Viewer->value],
            ])
            ->assertConflict();

        $this->assertTrue($lastAdmin->fresh()?->hasRole(RoleEnum::Admin->value));
    }

    public function test_an_administrator_can_be_demoted_while_another_remains(): void
    {
        $demoted = $this->administrator();
        $this->administrator();

        $this->actingAs($this->administrator())
            ->putJson(route('users.roles.update', $demoted), [
                'roles' => [RoleEnum::Viewer->value],
            ])
            ->assertOk();

        $this->assertFalse($demoted->fresh()?->hasRole(RoleEnum::Admin->value));
    }

    public function test_a_user_without_the_manage_permission_is_refused(): void
    {
        $target = $this->userWithRole(RoleEnum::Viewer);

        $this->actingAs($this->userWithRole(RoleEnum::Manager))
            ->putJson(route('users.roles.update', $target), [
                'roles' => [RoleEnum::Sales->value],
            ])
            ->assertForbidden();
    }

    private function administrator(): User
    {
        return $this->userWithRole(RoleEnum::Admin);
    }

    private function userWithRole(RoleEnum $role): User
    {
        return User::factory()->create()->assignRole($role->value);
    }
}
