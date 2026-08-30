<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Увольнение: сотрудник в системе остаётся, платформа для него закрывается.
 * И удаление — крайняя мера, доступная одному суперадминистратору.
 */
final class UserDismissalTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_an_administrator_dismisses_a_colleague(): void
    {
        $user = $this->userWith(Permission::ViewCourses);

        $this->actingAs($this->administrator())
            ->postJson(route('users.dismiss', $user))
            ->assertOk()
            ->assertJsonPath('data.id', $user->getKey());

        $this->assertNotNull($user->fresh()?->dismissed_at);
    }

    public function test_a_dismissed_person_cannot_sign_in(): void
    {
        $user = User::factory()->dismissed()->create();

        $this->postJson(route('auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    /**
     * Дверь закрыта не только на входе: страница, оставшаяся открытой, тоже
     * ничего больше не получит.
     */
    public function test_a_dismissed_person_is_refused_everywhere(): void
    {
        $user = User::factory()->dismissed()->create();
        $user->givePermissionTo(Permission::ViewCourses->value);

        $this->actingAs($user)->getJson(route('auth.user'))->assertUnauthorized();
        $this->actingAs($user)->getJson(route('lms.courses.index'))->assertUnauthorized();
        $this->actingAs($user)->getJson(route('chat.conversations.index'))->assertUnauthorized();
    }

    public function test_dismissal_ends_the_sessions_already_open(): void
    {
        // Хранилище сессий — база: в тестах драйвер иной, и без подмены
        // проверять было бы нечего.
        config()->set('session.driver', 'database');

        $user = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'session-of-the-dismissed',
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'tests',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->actingAs($this->administrator())
            ->postJson(route('users.dismiss', $user))
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->getKey()]);
    }

    public function test_nobody_dismisses_themselves(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->postJson(route('users.dismiss', $administrator))
            ->assertStatus(409);

        $this->assertNull($administrator->fresh()?->dismissed_at);
    }

    public function test_an_administrator_may_not_dismiss_a_superadmin(): void
    {
        $superAdministrator = $this->superAdministrator();

        $this->actingAs($this->administrator())
            ->postJson(route('users.dismiss', $superAdministrator))
            ->assertStatus(409);

        $this->assertNull($superAdministrator->fresh()?->dismissed_at);
    }

    public function test_a_superadmin_dismisses_another_superadmin(): void
    {
        $dismissed = $this->superAdministrator();

        $this->actingAs($this->superAdministrator())
            ->postJson(route('users.dismiss', $dismissed))
            ->assertOk();

        $this->assertNotNull($dismissed->fresh()?->dismissed_at);
    }

    /**
     * Уволенный не увольняет: сессии его оборваны, а запрос не проходит дальше
     * EnsureEmployed — правами он по-прежнему обладает, но не действует.
     */
    public function test_a_dismissed_administrator_dismisses_nobody(): void
    {
        $dismissed = User::factory()->dismissed()->create()->assignRole(AccessLevel::Admin->value);
        $colleague = User::factory()->create();

        $this->actingAs($dismissed)
            ->postJson(route('users.dismiss', $colleague))
            ->assertUnauthorized();

        $this->assertNull($colleague->fresh()?->dismissed_at);
    }

    public function test_a_person_without_the_right_cannot_dismiss_anybody(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->userWith(Permission::ViewUsers))
            ->postJson(route('users.dismiss', $user))
            ->assertForbidden();
    }

    public function test_reinstatement_gives_back_the_standing_and_the_ticked_rights(): void
    {
        $user = $this->userWith(Permission::ViewCourses);

        $this->actingAs($this->administrator())->postJson(route('users.dismiss', $user))->assertOk();

        $this->actingAs($this->administrator())
            ->deleteJson(route('users.reinstate', $user))
            ->assertOk()
            ->assertJsonPath('data.dismissed_at', null)
            ->assertJsonPath('data.own_permissions', [Permission::ViewCourses->value]);

        $this->assertNull($user->fresh()?->dismissed_at);

        $this->postJson(route('auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_a_superadmin_deletes_a_dismissed_record_for_good(): void
    {
        $user = User::factory()->dismissed()->create();

        $this->actingAs($this->superAdministrator())
            ->deleteJson(route('users.destroy', $user))
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $user->getKey()]);
    }

    public function test_only_a_dismissed_record_is_deleted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->superAdministrator())
            ->deleteJson(route('users.destroy', $user))
            ->assertStatus(409);

        $this->assertDatabaseHas('users', ['id' => $user->getKey()]);
    }

    public function test_an_administrator_cannot_delete_a_record(): void
    {
        $user = User::factory()->dismissed()->create();

        $this->actingAs($this->administrator())
            ->deleteJson(route('users.destroy', $user))
            ->assertStatus(409);

        $this->assertDatabaseHas('users', ['id' => $user->getKey()]);
    }

    /**
     * Уволенного не предлагают там, где выбирают человека.
     */
    public function test_a_dismissed_person_is_not_offered_to_pick(): void
    {
        $dismissed = User::factory()->dismissed()->create(['first_name' => 'Пров']);
        $working = User::factory()->create(['first_name' => 'Прохор']);

        $this->actingAs($this->administrator())
            ->getJson(route('chat.contacts', ['search' => 'Про']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $working->getKey());

        $this->actingAs($this->userWith(Permission::ViewCourses, Permission::ManageEnrollments))
            ->getJson(route('lms.plans.people', ['search' => 'Про']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $working->getKey());

        $this->assertSame(0, User::query()->employed()->whereKey($dismissed->getKey())->count());
    }

    /**
     * Список пользователей уволенных показывает — там их и возвращают в строй.
     */
    public function test_the_staff_list_still_shows_the_dismissed(): void
    {
        $dismissed = User::factory()->dismissed()->create();

        $this->actingAs($this->administrator())
            ->getJson(route('users.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $dismissed->getKey()]);
    }

    public function test_an_administrator_dismisses_another_administrator(): void
    {
        $colleague = User::factory()->create()->assignRole(AccessLevel::Admin->value);

        $this->actingAs($this->administrator())
            ->postJson(route('users.dismiss', $colleague))
            ->assertOk();

        $this->assertNotNull($colleague->fresh()?->dismissed_at);
    }
}
