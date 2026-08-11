<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * An administrator staffing the system: adding colleagues, correcting their
 * records and choosing what they may do.
 */
final class UserManagementTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_an_administrator_can_create_a_user(): void
    {
        $response = $this->actingAs($this->administrator())
            ->postJson(route('users.store'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'middle_name' => 'Августовна',
                'email' => 'ada@bismar.test',
                'password' => 'correct-horse-battery-staple',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Лавлейс Ада Августовна')
            ->assertJsonPath('data.own_permissions', [])
            ->assertJsonMissingPath('data.password');

        $created = User::firstWhere('email', 'ada@bismar.test');

        $this->assertNotNull($created);
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $created->password));
        $this->assertSame($response->json('data.id'), $created->id);
    }

    public function test_a_duplicate_address_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@bismar.test']);

        $this->actingAs($this->administrator())
            ->postJson(route('users.store'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'taken@bismar.test',
                'password' => 'correct-horse-battery-staple',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_a_user_without_the_manage_permission_cannot_create_anyone(): void
    {
        $this->actingAs($this->userWith(Permission::ViewUsers))
            ->postJson(route('users.store'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
                'password' => 'correct-horse-battery-staple',
            ])
            ->assertForbidden();
    }

    public function test_an_administrator_can_correct_a_users_record(): void
    {
        $user = User::factory()->create(['last_name' => 'Старая', 'middle_name' => 'Отчество']);

        $this->actingAs($this->administrator())
            ->putJson(route('users.update', $user), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Лавлейс Ада');

        // An omitted patronymic clears it — the form always sends the whole record.
        $this->assertNull($user->refresh()->middle_name);
    }

    public function test_a_password_is_left_alone_unless_a_new_one_is_sent(): void
    {
        $user = User::factory()->create();
        $before = $user->password;

        $this->actingAs($this->administrator())
            ->putJson(route('users.update', $user), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
            ])
            ->assertOk();

        $this->assertSame($before, $user->refresh()->password);
    }

    public function test_an_administrator_can_reset_a_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->administrator())
            ->putJson(route('users.update', $user), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => 'ada@bismar.test',
                'password' => 'a-brand-new-passphrase',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-passphrase', $user->refresh()->password));
    }
}
