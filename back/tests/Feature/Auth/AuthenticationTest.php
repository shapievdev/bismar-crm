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
            'phone' => $user->phone,
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.phone', $user->phone);

        $this->assertAuthenticatedAs($user);
    }

    /**
     * Номер набирают как придётся — через восьмёрку, со скобками, с дефисами, —
     * и это один и тот же человек. Приведение стоит до проверки, иначе вход
     * зависел бы от того, как сегодня набрали.
     */
    public function test_a_phone_is_accepted_however_it_was_typed(): void
    {
        $user = User::factory()->create(['phone' => '+79990009977']);

        $this->postJson(route('auth.login'), [
            'phone' => '8 (999) 000-99-77',
            'password' => 'password',
        ])->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    /**
     * Почта осталась способом связи, но перестала быть логином: вход по ней не
     * должен ни срабатывать, ни проходить проверку молча.
     */
    public function test_an_email_is_no_longer_a_login(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('auth.login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->assertGuest();
    }

    /**
     * Сотрудник, заведённый без номера, войти не может — и именно поэтому номер
     * теперь обязателен везде, где человека заводят.
     */
    public function test_an_account_without_a_phone_cannot_be_signed_into(): void
    {
        User::factory()->create(['phone' => null]);

        $this->postJson(route('auth.login'), [
            'phone' => '+79990009977',
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->assertGuest();
    }

    public function test_a_user_cannot_log_in_with_an_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('auth.login'), [
            'phone' => $user->phone,
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $ignored) {
            $this->postJson(route('auth.login'), [
                'phone' => $user->phone,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $response = $this->postJson(route('auth.login'), [
            'phone' => $user->phone,
            'password' => 'password',
        ]);

        $response->assertUnprocessable();
        $this->assertStringContainsString(
            'Too many login attempts',
            $response->json('errors.phone.0'),
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
            'phone' => $user->phone,
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

    /**
     * Записаться самому нельзя: учётную запись заводит администратор.
     *
     * Проверка живёт здесь, потому что маршрута больше нет вовсе, а не потому,
     * что он кому-то отказывает: адрес был публичным, и вернуться он может
     * только вместе с решением его вернуть. Обращение к нему — прежним телом
     * запроса, чтобы забытый на фронте вызов не выглядел «ошибкой в данных».
     */
    public function test_nobody_may_register_themselves(): void
    {
        $this->postJson('/api/auth/register', [
            'last_name' => 'Петров',
            'first_name' => 'Пётр',
            'email' => 'petrov@bismar.test',
            'phone' => '+79990009900',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'petrov@bismar.test']);
    }
}
