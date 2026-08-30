<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * What a person may do: their standing, and the permissions ticked for them.
 */
final class UserAccessTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * Regression test for the guard mismatch: `auth:sanctum` rewrites
     * `auth.defaults.guard` mid-request, so a dynamically resolved guard made
     * every ordinary user's permissions invisible. Administrators hid the bug
     * because Gate::before waves them through.
     */
    public function test_a_permission_granted_to_a_person_actually_works(): void
    {
        $user = $this->userWith(Permission::ViewUsers);

        $this->assertTrue($user->can(Permission::ViewUsers->value));

        $this->actingAs($user)->getJson(route('users.index'))->assertOk();
    }

    public function test_a_person_without_the_permission_is_refused(): void
    {
        $this->actingAs($this->userWith(Permission::ViewCourses))
            ->getJson(route('users.index'))
            ->assertForbidden();
    }

    public function test_an_administrator_can_grant_permissions_one_by_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->administrator())
            ->putJson(route('users.access.update', $user), [
                'level' => AccessLevel::User->value,
                'permissions' => [Permission::ViewDeals->value, Permission::CreateDeals->value],
            ])
            ->assertOk()
            ->assertJsonPath('data.level', AccessLevel::User->value)
            ->assertJsonCount(2, 'data.own_permissions');

        $this->assertTrue($user->fresh()?->can(Permission::CreateDeals->value));
        $this->assertFalse($user->fresh()?->can(Permission::DeleteDeals->value));
    }

    public function test_permissions_are_replaced_rather_than_added_to(): void
    {
        $user = $this->userWith(Permission::ViewDeals, Permission::CreateDeals);

        $this->actingAs($this->administrator())
            ->putJson(route('users.access.update', $user), [
                'level' => AccessLevel::User->value,
                'permissions' => [Permission::ViewContacts->value],
            ])
            ->assertOk()
            ->assertJsonPath('data.own_permissions', [Permission::ViewContacts->value]);

        $this->assertFalse($user->fresh()?->can(Permission::ViewDeals->value));
    }

    public function test_an_administrator_carries_everything_without_being_granted_it(): void
    {
        $admin = $this->administrator();

        $this->assertTrue($admin->can(Permission::DeleteDeals->value));
        $this->assertSame(0, $admin->permissions()->count());

        $this->actingAs($admin)
            ->getJson(route('auth.user'))
            ->assertOk()
            ->assertJsonPath('data.level', AccessLevel::Admin->value)
            ->assertJsonCount(count(Permission::cases()), 'data.permissions');
    }

    /**
     * Raising someone clears their ticks: they carry everything by standing, so
     * leftover grants would be dead rows that reappear on a demotion.
     */
    public function test_raising_someone_to_administrator_clears_their_own_grants(): void
    {
        $user = $this->userWith(Permission::ViewDeals);

        $this->actingAs($this->superAdministrator())
            ->putJson(route('users.access.update', $user), [
                'level' => AccessLevel::Admin->value,
                'permissions' => [Permission::ViewDeals->value],
            ])
            ->assertOk()
            ->assertJsonPath('data.own_permissions', []);

        $this->assertSame(0, $user->fresh()?->permissions()->count());
    }

    public function test_an_administrator_can_appoint_another_administrator(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->administrator())
            ->putJson(route('users.access.update', $user), [
                'level' => AccessLevel::Admin->value,
                'permissions' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.level', AccessLevel::Admin->value);

        $this->assertSame(AccessLevel::Admin, $user->fresh()?->accessLevel());
    }

    public function test_an_administrator_can_remove_another_administrator(): void
    {
        $peer = $this->administrator();

        $this->actingAs($this->administrator())
            ->putJson(route('users.access.update', $peer), [
                'level' => AccessLevel::User->value,
                'permissions' => [],
            ])
            ->assertOk();

        $this->assertSame(AccessLevel::User, $peer->fresh()?->accessLevel());
    }

    public function test_an_administrator_cannot_appoint_a_superadmin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->administrator())
            ->putJson(route('users.access.update', $user), [
                'level' => AccessLevel::SuperAdmin->value,
                'permissions' => [],
            ])
            ->assertConflict();

        $this->assertSame(AccessLevel::User, $user->fresh()?->accessLevel());
    }

    public function test_an_administrator_cannot_remove_a_superadmin(): void
    {
        $superAdministrator = $this->superAdministrator();
        $this->superAdministrator();

        $this->actingAs($this->administrator())
            ->putJson(route('users.access.update', $superAdministrator), [
                'level' => AccessLevel::Admin->value,
                'permissions' => [],
            ])
            ->assertConflict();

        $this->assertSame(AccessLevel::SuperAdmin, $superAdministrator->fresh()?->accessLevel());
    }

    /**
     * The right to edit access tickets permissions; handing someone everything
     * by standing takes standing of your own.
     */
    public function test_an_ordinary_person_who_may_edit_access_cannot_appoint_an_administrator(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->userWith(Permission::ManageUsers))
            ->putJson(route('users.access.update', $user), [
                'level' => AccessLevel::Admin->value,
                'permissions' => [],
            ])
            ->assertConflict();

        $this->assertSame(AccessLevel::User, $user->fresh()?->accessLevel());
    }

    public function test_a_superadmin_can_appoint_an_administrator(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->superAdministrator())
            ->putJson(route('users.access.update', $user), [
                'level' => AccessLevel::Admin->value,
                'permissions' => [],
            ])
            ->assertOk();

        $this->assertSame(AccessLevel::Admin, $user->fresh()?->accessLevel());
    }

    public function test_an_administrator_may_still_edit_an_ordinary_persons_permissions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->administrator())
            ->putJson(route('users.access.update', $user), [
                'level' => AccessLevel::User->value,
                'permissions' => [Permission::ViewCourses->value],
            ])
            ->assertOk();

        $this->assertTrue($user->fresh()?->can(Permission::ViewCourses->value));
    }

    public function test_nobody_can_demote_themselves(): void
    {
        $admin = $this->administrator();
        $this->superAdministrator();

        $this->actingAs($admin)
            ->putJson(route('users.access.update', $admin), [
                'level' => AccessLevel::User->value,
                'permissions' => [],
            ])
            ->assertConflict();

        $this->assertSame(AccessLevel::Admin, $admin->fresh()?->accessLevel());
    }

    public function test_the_last_superadmin_cannot_be_demoted(): void
    {
        $last = $this->superAdministrator();
        $other = $this->superAdministrator();

        // Demoted by a peer, so "cannot demote yourself" is not what is tested.
        $this->actingAs($other)
            ->putJson(route('users.access.update', $last), [
                'level' => AccessLevel::User->value,
                'permissions' => [],
            ])
            ->assertOk();

        $this->actingAs($other)
            ->putJson(route('users.access.update', $other), [
                'level' => AccessLevel::User->value,
                'permissions' => [],
            ])
            ->assertConflict();

        $this->assertSame(AccessLevel::SuperAdmin, $other->fresh()?->accessLevel());
    }

    public function test_an_unknown_permission_is_rejected(): void
    {
        $this->actingAs($this->administrator())
            ->putJson(route('users.access.update', User::factory()->create()), [
                'level' => AccessLevel::User->value,
                'permissions' => ['everything.always'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_someone_without_the_manage_permission_is_refused(): void
    {
        $this->actingAs($this->userWith(Permission::ViewUsers))
            ->putJson(route('users.access.update', User::factory()->create()), [
                'level' => AccessLevel::User->value,
                'permissions' => [],
            ])
            ->assertForbidden();
    }
}
