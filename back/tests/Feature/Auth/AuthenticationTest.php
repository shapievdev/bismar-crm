<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\ActsAsSpaClient;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use ActsAsSpaClient, RefreshDatabase;

    public function test_a_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.email', $user->email);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_user_cannot_log_in_with_an_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('auth.login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $ignored) {
            $this->postJson(route('auth.login'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $response = $this->postJson(route('auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertUnprocessable();
        $this->assertStringContainsString(
            'Too many login attempts',
            $response->json('errors.email.0'),
        );
        $this->assertGuest();
    }

    public function test_the_authenticated_user_can_be_retrieved(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('auth.user'))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_guests_cannot_retrieve_the_authenticated_user(): void
    {
        $this->getJson(route('auth.user'))->assertUnauthorized();
    }

    /**
     * A guest request that does not ask for JSON used to be answered with a 500:
     * the `auth` middleware built its redirect from the framework's default
     * `route('login')`, and this application has no route by that name.
     */
    public function test_a_guest_request_that_does_not_ask_for_json_is_still_unauthorized(): void
    {
        $this->withHeader('Accept', 'text/html')
            ->get(route('auth.user'))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_a_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->postJson(route('auth.logout'))->assertNoContent();

        $this->assertGuest('web');

        // Each real HTTP request resolves its guards from a fresh container; the
        // test client reuses one, so drop the cached guards to avoid reading the
        // user that Sanctum's request guard resolved before the logout.
        Auth::forgetGuards();

        $this->getJson(route('auth.user'))->assertUnauthorized();
    }
}
