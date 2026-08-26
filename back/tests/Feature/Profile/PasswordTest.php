<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Support\Authorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActsAsSpaClient;
use Tests\TestCase;

final class PasswordTest extends TestCase
{
    use ActsAsSpaClient, RefreshDatabase;

    private const CURRENT = 'password';

    private const REPLACEMENT = 'sunflower-canal-73';

    public function test_a_user_can_change_their_own_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('profile.password.update'), [
                'current_password' => self::CURRENT,
                'password' => self::REPLACEMENT,
                'password_confirmation' => self::REPLACEMENT,
            ])
            ->assertNoContent();

        $stored = $user->refresh()->password;

        $this->assertTrue(Hash::check(self::REPLACEMENT, $stored));
        $this->assertFalse(Hash::check(self::CURRENT, $stored));
    }

    public function test_the_new_password_is_the_one_that_signs_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('profile.password.update'), [
                'current_password' => self::CURRENT,
                'password' => self::REPLACEMENT,
                'password_confirmation' => self::REPLACEMENT,
            ])
            ->assertNoContent();

        // Back to being a stranger: `actingAs` seated a user on the guard, and
        // the point here is what the login form makes of the two passwords.
        //
        // The default guard has to be put back by hand. `auth:sanctum` wrote
        // "sanctum" into the config during the request above, and the test
        // client reuses that container — a real login arrives in a fresh one,
        // never having gone through that middleware. See App\Support\Authorization.
        Auth::forgetGuards();
        config()->set('auth.defaults.guard', Authorization::GUARD);
        $this->flushSession();

        $this->postJson(route('auth.login'), [
            'email' => $user->email,
            'password' => self::CURRENT,
        ])->assertUnprocessable();

        $this->postJson(route('auth.login'), [
            'email' => $user->email,
            'password' => self::REPLACEMENT,
        ])->assertOk();
    }

    public function test_the_current_password_has_to_be_right(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('profile.password.update'), [
                'current_password' => 'not-the-one',
                'password' => self::REPLACEMENT,
                'password_confirmation' => self::REPLACEMENT,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        // Being signed in is not enough on its own: the old password stands.
        $this->assertTrue(Hash::check(self::CURRENT, $user->refresh()->password));
    }

    public function test_the_new_password_has_to_be_confirmed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('profile.password.update'), [
                'current_password' => self::CURRENT,
                'password' => self::REPLACEMENT,
                'password_confirmation' => 'typed-it-differently',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_the_new_password_has_to_be_long_enough(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('profile.password.update'), [
                'current_password' => self::CURRENT,
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_the_new_password_has_to_differ_from_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('profile.password.update'), [
                'current_password' => self::CURRENT,
                'password' => self::CURRENT,
                'password_confirmation' => self::CURRENT,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_the_change_leaves_the_user_signed_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('profile.password.update'), [
                'current_password' => self::CURRENT,
                'password' => self::REPLACEMENT,
                'password_confirmation' => self::REPLACEMENT,
            ])
            ->assertNoContent();

        $this->getJson(route('auth.user'))->assertOk()->assertJsonPath('data.id', $user->id);
    }

    /**
     * The other half of that: a session opened before the change is holding the
     * hash of the password that has just been replaced, and Sanctum's
     * AuthenticateSession turns it away on its next request.
     */
    public function test_a_session_left_on_the_old_password_is_signed_out(): void
    {
        $user = User::factory()->create();
        $before = $user->password;

        $user->forceFill(['password' => self::REPLACEMENT])->save();

        $this->actingAs($user)
            ->withSession(['password_hash_'.Authorization::GUARD => $before])
            ->getJson(route('auth.user'))
            ->assertUnauthorized();
    }

    /**
     * A browser that ticked "remember me" is not held by the session, so the
     * change has to reach it through the token its cookie is matched against.
     */
    public function test_a_remembered_device_is_cut_loose_by_the_change(): void
    {
        $user = User::factory()->create(['remember_token' => 'the-old-token']);

        $this->actingAs($user)
            ->putJson(route('profile.password.update'), [
                'current_password' => self::CURRENT,
                'password' => self::REPLACEMENT,
                'password_confirmation' => self::REPLACEMENT,
            ])
            ->assertNoContent();

        $this->assertNotSame('the-old-token', $user->refresh()->remember_token);
    }

    public function test_a_guest_cannot_change_a_password(): void
    {
        $this->putJson(route('profile.password.update'), [
            'current_password' => self::CURRENT,
            'password' => self::REPLACEMENT,
            'password_confirmation' => self::REPLACEMENT,
        ])->assertUnauthorized();
    }
}
