<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\TestCase;

final class RoleManagementTest extends TestCase
{
    use ActsAsSpaClient, RefreshDatabase;

    public function test_an_administrator_can_list_roles_with_their_permissions(): void
    {
        $response = $this->actingAs($this->administrator())
            ->getJson(route('roles.index'))
            ->assertOk();

        $names = array_column($response->json('data'), 'name');

        $this->assertEqualsCanonicalizing(RoleEnum::values(), $names);
        $this->assertContains(
            Permission::ViewContacts->value,
            $response->json('data.0.permissions'),
        );
    }

    public function test_a_user_without_the_roles_permission_is_refused(): void
    {
        $this->actingAs($this->userWithRole(RoleEnum::Sales))
            ->getJson(route('roles.index'))
            ->assertForbidden();
    }

    public function test_guests_are_refused(): void
    {
        $this->getJson(route('roles.index'))->assertUnauthorized();
    }

    public function test_an_administrator_can_create_a_role(): void
    {
        $this->actingAs($this->administrator())
            ->postJson(route('roles.store'), [
                'name' => 'support',
                'permissions' => [Permission::ViewContacts->value, Permission::ViewDeals->value],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'support')
            ->assertJsonPath('data.is_built_in', false);

        $role = Role::findByName('support');

        $this->assertTrue($role->hasPermissionTo(Permission::ViewContacts->value));
        $this->assertFalse($role->hasPermissionTo(Permission::DeleteDeals->value));
    }

    public function test_a_role_cannot_be_granted_an_unknown_permission(): void
    {
        $this->actingAs($this->administrator())
            ->postJson(route('roles.store'), [
                'name' => 'support',
                'permissions' => ['contacts.obliterate'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_updating_a_role_replaces_its_permissions(): void
    {
        $this->actingAs($this->administrator())
            ->putJson(route('roles.update', RoleEnum::Viewer->value), [
                'permissions' => [Permission::ViewContacts->value],
            ])
            ->assertOk()
            ->assertJsonPath('data.permissions', [Permission::ViewContacts->value]);

        $viewer = Role::findByName(RoleEnum::Viewer->value);

        $this->assertTrue($viewer->hasPermissionTo(Permission::ViewContacts->value));
        $this->assertFalse($viewer->hasPermissionTo(Permission::ViewDeals->value));
    }

    public function test_a_built_in_role_cannot_be_deleted(): void
    {
        $this->actingAs($this->administrator())
            ->deleteJson(route('roles.destroy', RoleEnum::Viewer->value))
            ->assertConflict();

        $this->assertNotNull(Role::findByName(RoleEnum::Viewer->value));
    }

    public function test_a_role_still_assigned_to_someone_cannot_be_deleted(): void
    {
        $role = Role::create(['name' => 'support', 'guard_name' => 'web']);
        User::factory()->create()->assignRole($role);

        $this->actingAs($this->administrator())
            ->deleteJson(route('roles.destroy', $role->name))
            ->assertConflict();

        $this->assertNotNull(Role::findByName('support'));
    }

    public function test_an_unused_custom_role_can_be_deleted(): void
    {
        $role = Role::create(['name' => 'support', 'guard_name' => 'web']);

        $this->actingAs($this->administrator())
            ->deleteJson(route('roles.destroy', $role->name))
            ->assertNoContent();

        $this->assertDatabaseMissing('roles', ['name' => 'support']);
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
