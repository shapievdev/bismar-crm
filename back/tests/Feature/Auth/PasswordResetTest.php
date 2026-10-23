<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Passport;
use RuntimeException;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Забытый пароль: попросить ссылку и поставить новый.
 *
 * Два правила стоят за половиной этих проверок. Первое: форма просьбы отвечает
 * одинаково всегда — иначе перебором по ней узнавали бы, кто в компании
 * работает. Второе: за новым паролем закрываются двери, открытые прежним, —
 * смена пароля после того, как его подсмотрели, обязана что-то менять.
 */
final class PasswordResetTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /* ---------- Просьба о ссылке ---------- */

    public function test_an_employee_asks_for_a_link_by_email(): void
    {
        Notification::fake();

        $person = User::factory()->create(['email' => 'kurabanov@bismar.pro']);

        $this->postJson(route('auth.password.forgot'), ['login' => 'kurabanov@bismar.pro'])
            ->assertOk();

        Notification::assertSentTo($person, ResetPasswordLink::class);
    }

    /**
     * И по номеру — тем же полем.
     *
     * Входят в платформу по телефону, и его человек помнит; записана ли у него
     * почта — обычно нет. Заставить угадать поле значило бы отказать половине
     * просящих молча.
     */
    public function test_an_employee_asks_for_a_link_by_phone(): void
    {
        Notification::fake();

        $person = User::factory()->create([
            'phone' => '+79990009977',
            'email' => 'kurabanov@bismar.pro',
        ]);

        // Набрано так, как номер набирают руками, — не так, как он хранится.
        $this->postJson(route('auth.password.forgot'), ['login' => '8 (999) 000-99-77'])
            ->assertOk();

        Notification::assertSentTo($person, ResetPasswordLink::class);
    }

    /** Регистр почты не имеет значения: базы живут в коллации C. */
    public function test_the_case_of_the_email_does_not_matter(): void
    {
        Notification::fake();

        $person = User::factory()->create(['email' => 'Kurabanov@bismar.pro']);

        $this->postJson(route('auth.password.forgot'), ['login' => 'kurabanov@BISMAR.pro'])
            ->assertOk();

        Notification::assertSentTo($person, ResetPasswordLink::class);
    }

    /**
     * Неизвестный логин — тот же ответ, что и известный.
     *
     * Главная проверка этого раздела: разные ответы превратили бы форму в
     * справочник сотрудников компании.
     */
    public function test_an_unknown_login_is_answered_exactly_like_a_known_one(): void
    {
        Notification::fake();

        $person = User::factory()->create(['email' => 'kurabanov@bismar.pro']);

        $known = $this->postJson(route('auth.password.forgot'), ['login' => 'kurabanov@bismar.pro'])
            ->assertOk();

        $unknown = $this->postJson(route('auth.password.forgot'), ['login' => 'nobody@bismar.pro'])
            ->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));

        Notification::assertSentTo($person, ResetPasswordLink::class);
        Notification::assertCount(1);
    }

    /** Без записанной почты писать некуда — но ответ всё тот же. */
    public function test_an_employee_without_an_email_gets_the_same_answer_and_no_letter(): void
    {
        Notification::fake();

        User::factory()->create(['phone' => '+79990009977', 'email' => null]);

        $this->postJson(route('auth.password.forgot'), ['login' => '+79990009977'])
            ->assertOk();

        Notification::assertNothingSent();
    }

    /** Уволенному ссылку не выдают: войти он всё равно не может. */
    public function test_a_dismissed_employee_gets_no_link(): void
    {
        Notification::fake();

        User::factory()->dismissed()->create(['email' => 'ushel@bismar.pro']);

        $this->postJson(route('auth.password.forgot'), ['login' => 'ushel@bismar.pro'])
            ->assertOk();

        Notification::assertNothingSent();
    }

    /** Удалённого нет нигде — и здесь тоже. */
    public function test_a_deleted_record_gets_no_link(): void
    {
        Notification::fake();

        $person = User::factory()->dismissed()->create(['email' => 'udalen@bismar.pro']);
        $person->delete();

        $this->postJson(route('auth.password.forgot'), ['login' => 'udalen@bismar.pro'])
            ->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * Второй ссылки подряд не выдают.
     *
     * Предел защищает не нас, а владельца ящика: иначе чужими просьбами ему
     * завалили бы почту.
     */
    public function test_a_second_request_in_a_row_sends_nothing(): void
    {
        Notification::fake();

        $person = User::factory()->create(['email' => 'kurabanov@bismar.pro']);

        $this->postJson(route('auth.password.forgot'), ['login' => 'kurabanov@bismar.pro'])->assertOk();
        $this->postJson(route('auth.password.forgot'), ['login' => 'kurabanov@bismar.pro'])->assertOk();

        Notification::assertSentToTimes($person, ResetPasswordLink::class, 1);
    }

    /**
     * Почтовый сервер не ответил — форма отвечает как обычно.
     *
     * Иначе сотрудник, которому не повезло попасть на обрыв связи с Яндексом,
     * увидел бы пятисотую и решил, что сломалась платформа. Сам отказ при этом
     * не пропадает — он уходит в журнал.
     */
    public function test_a_broken_mail_server_does_not_break_the_form(): void
    {
        Mail::shouldReceive('mailer')->andThrow(new RuntimeException('Connection could not be established'));

        $person = User::factory()->create(['email' => 'kurabanov@bismar.pro']);

        $this->postJson(route('auth.password.forgot'), ['login' => 'kurabanov@bismar.pro'])
            ->assertOk()
            ->assertJsonStructure(['message']);

        // Токен всё же выдан: в худшем случае человек попросит ссылку заново.
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $person->email]);
    }

    public function test_an_empty_login_is_refused_by_the_form(): void
    {
        $this->postJson(route('auth.password.forgot'), ['login' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('login');
    }

    /* ---------- Письмо ---------- */

    /**
     * Ссылка ведёт на страницу приложения, а не на маршрут Laravel: форма живёт
     * в Nuxt, и адрес собирается из `app.frontend_url`.
     */
    public function test_the_letter_links_to_the_form_in_the_application(): void
    {
        Notification::fake();

        $person = User::factory()->create(['email' => 'kurabanov@bismar.pro']);

        $this->postJson(route('auth.password.forgot'), ['login' => 'kurabanov@bismar.pro'])->assertOk();

        Notification::assertSentTo($person, ResetPasswordLink::class, function (ResetPasswordLink $letter) use ($person): bool {
            $mail = $letter->toMail($person);

            $link = (string) ($mail->actionUrl ?? '');

            return str_starts_with($link, rtrim((string) config('app.frontend_url'), '/').'/password/reset?')
                && str_contains($link, 'user='.$person->getKey())
                && str_contains($link, 'token=')
                // Почты в ссылке нет намеренно: она осела бы в истории браузера
                // и в журналах по дороге, а пускает всё равно токен.
                && ! str_contains($link, 'kurabanov');
        });
    }

    /**
     * Письмо целиком по-русски — включая то, что дописывает фреймворк.
     *
     * Шаблон письма подставляет две строки своими словами, и без перевода
     * (`lang/ru.json` при `app.locale`) человек получал бы русский текст с
     * английским хвостом: «If you're having trouble clicking…». Проверяется
     * собранная разметка, а не наши строки: наши и так на виду.
     */
    public function test_the_letter_has_no_english_tail(): void
    {
        $person = User::factory()->create(['email' => 'kurabanov@bismar.pro']);

        $html = (string) (new ResetPasswordLink('token-dlya-proverki'))
            ->toMail($person)
            ->render();

        $this->assertStringContainsString('Если кнопка «Сменить пароль» не нажимается', $html);
        $this->assertStringContainsString('Все права защищены', $html);
        $this->assertStringNotContainsString('trouble clicking', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
    }

    /* ---------- Новый пароль ---------- */

    public function test_a_valid_link_changes_the_password_and_lets_them_in(): void
    {
        $person = User::factory()->create([
            'phone' => '+79990009977',
            'email' => 'kurabanov@bismar.pro',
        ]);

        $token = Password::broker()->getRepository()->create($person);

        $this->postJson(route('auth.password.reset'), [
            'user' => $person->getKey(),
            'token' => $token,
            'password' => 'novyj-parol-2026',
            'password_confirmation' => 'novyj-parol-2026',
        ])->assertOk();

        $this->assertTrue(Hash::check('novyj-parol-2026', (string) $person->fresh()?->password));

        // И вход этим паролем работает — по номеру, как и всегда.
        $this->postJson(route('auth.login'), [
            'phone' => '+79990009977',
            'password' => 'novyj-parol-2026',
        ])->assertOk();
    }

    /** Ссылка одноразовая: второй раз та же не работает. */
    public function test_a_link_works_only_once(): void
    {
        $person = User::factory()->create(['email' => 'kurabanov@bismar.pro']);

        $token = Password::broker()->getRepository()->create($person);

        $payload = [
            'user' => $person->getKey(),
            'token' => $token,
            'password' => 'novyj-parol-2026',
            'password_confirmation' => 'novyj-parol-2026',
        ];

        $this->postJson(route('auth.password.reset'), $payload)->assertOk();

        $this->postJson(route('auth.password.reset'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    public function test_a_made_up_token_is_refused(): void
    {
        $person = User::factory()->create(['email' => 'kurabanov@bismar.pro']);

        Password::broker()->getRepository()->create($person);

        $this->postJson(route('auth.password.reset'), [
            'user' => $person->getKey(),
            'token' => Str::random(64),
            'password' => 'novyj-parol-2026',
            'password_confirmation' => 'novyj-parol-2026',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');

        $this->assertFalse(Hash::check('novyj-parol-2026', (string) $person->fresh()?->password));
    }

    /** Ссылка, выданная до увольнения, после него не срабатывает. */
    public function test_a_link_issued_before_a_dismissal_stops_working(): void
    {
        $person = User::factory()->create(['email' => 'kurabanov@bismar.pro']);

        $token = Password::broker()->getRepository()->create($person);

        $person->forceFill(['dismissed_at' => now()])->save();

        $this->postJson(route('auth.password.reset'), [
            'user' => $person->getKey(),
            'token' => $token,
            'password' => 'novyj-parol-2026',
            'password_confirmation' => 'novyj-parol-2026',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }

    public function test_the_two_passwords_must_match(): void
    {
        $person = User::factory()->create(['email' => 'kurabanov@bismar.pro']);

        $this->postJson(route('auth.password.reset'), [
            'user' => $person->getKey(),
            'token' => Password::broker()->getRepository()->create($person),
            'password' => 'novyj-parol-2026',
            'password_confirmation' => 'drugoj-parol-2026',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_a_short_password_is_refused(): void
    {
        $person = User::factory()->create(['email' => 'kurabanov@bismar.pro']);

        $this->postJson(route('auth.password.reset'), [
            'user' => $person->getKey(),
            'token' => Password::broker()->getRepository()->create($person),
            'password' => 'korotko',
            'password_confirmation' => 'korotko',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    /* ---------- Что закрывается вместе со сменой ---------- */

    /**
     * Сессии на других устройствах обрываются.
     *
     * Иначе смена пароля после того, как его подсмотрели, не меняла бы ничего:
     * тот, кто уже вошёл, остался бы внутри.
     */
    public function test_the_new_password_closes_the_sessions_the_old_one_opened(): void
    {
        config()->set('session.driver', 'database');

        $person = User::factory()->create(['email' => 'kurabanov@bismar.pro']);

        DB::table('sessions')->insert([
            'id' => 'session-of-the-forgetful',
            'user_id' => $person->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'tests',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->postJson(route('auth.password.reset'), [
            'user' => $person->getKey(),
            'token' => Password::broker()->getRepository()->create($person),
            'password' => 'novyj-parol-2026',
            'password_confirmation' => 'novyj-parol-2026',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['user_id' => $person->getKey()]);
    }

    /** И токены коннекторов — по той же причине. */
    public function test_the_new_password_puts_out_the_tokens_of_the_connector(): void
    {
        $person = User::factory()->create(['email' => 'kurabanov@bismar.pro']);

        $client = Passport::client()->newQuery()->forceCreate([
            'id' => Str::uuid()->toString(),
            'name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);

        $token = Passport::token()->newQuery()->forceCreate([
            'id' => Str::random(80),
            'user_id' => $person->getKey(),
            'client_id' => $client->getKey(),
            'scopes' => [Registrar::OAUTH_SCOPE],
            'revoked' => false,
            'expires_at' => now()->addDay(),
        ]);

        $this->postJson(route('auth.password.reset'), [
            'user' => $person->getKey(),
            'token' => Password::broker()->getRepository()->create($person),
            'password' => 'novyj-parol-2026',
            'password_confirmation' => 'novyj-parol-2026',
        ])->assertOk();

        $this->assertDatabaseHas('oauth_access_tokens', [
            'id' => $token->getKey(),
            'revoked' => true,
        ]);
    }
}
