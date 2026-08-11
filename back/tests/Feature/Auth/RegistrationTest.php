<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActsAsSpaClient;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use ActsAsSpaClient, RefreshDatabase;

    public function test_a_new_user_can_register_and_is_logged_in(): void
    {
        Event::fake(Registered::class);

        $response = $this->postJson(route('auth.register'), [
            'last_name' => 'Лавлейс',
            'first_name' => 'Ада',
            'middle_name' => 'Августовна',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Лавлейс Ада Августовна')
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonMissingPath('data.password');

        $user = User::firstWhere('email', 'ada@example.com');

        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $user->password));
        $this->assertAuthenticatedAs($user);
        Event::assertDispatched(Registered::class);
    }

    public function test_registration_requires_a_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson(route('auth.register'), [
            'last_name' => 'Лавлейс',
            'first_name' => 'Ада',
            'email' => 'taken@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_registration_requires_a_confirmed_password(): void
    {
        $this->postJson(route('auth.register'), [
            'last_name' => 'Лавлейс',
            'first_name' => 'Ада',
            'middle_name' => 'Августовна',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'something-else',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertGuest();
    }
}
