<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Passport;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Подключение коннектора: то, что происходит до первого инструмента.
 *
 * Проверяется не аналитика и не права, а обнаружение и вход — тот самый обмен,
 * с которого claude.ai начинает: отказ с указателем на метаданные, сами
 * метаданные, регистрация клиента и токен. Ошибка в любом из этих четырёх
 * означает коннектор, который не подключается, и причину в них не видно
 * изнутри — её видно только отсюда.
 */
final class McpConnectorTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Запрос без токена: 401 и указатель, у кого спрашивать разрешение. */
    public function test_an_unauthenticated_request_points_at_the_metadata(): void
    {
        $response = $this->postJson('/mcp', $this->handshake());

        $response->assertUnauthorized();

        $challenge = $response->headers->get('WWW-Authenticate');

        $this->assertNotNull($challenge);
        $this->assertStringContainsString('Bearer', (string) $challenge);
        // Адрес описания ресурса — вложенный, с путём сервера: именно по нему
        // коннектор узнаёт, что `resource` — это `/mcp`, а не корень сайта.
        $this->assertStringContainsString(
            'resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"',
            (string) $challenge,
        );
    }

    /**
     * Описание защищаемого ресурса (RFC 9728).
     *
     * `resource` обязан совпасть с адресом, который сотрудник вводит в
     * claude.ai, слово в слово: не совпади — и коннектор откажется подключаться,
     * ничего толком не объяснив.
     */
    public function test_the_protected_resource_metadata_names_the_server_itself(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()
            ->assertJson([
                'resource' => url('/mcp'),
                'authorization_servers' => [url('/')],
                'scopes_supported' => [Registrar::OAUTH_SCOPE],
            ]);
    }

    /**
     * Описание сервера авторизации (RFC 8414).
     *
     * S256 здесь не украшение: коннектор ходит публичным клиентом, без секрета,
     * и единственное, что защищает код авторизации, — проверка кода-испытания.
     */
    public function test_the_authorization_server_metadata_offers_pkce_and_refresh(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJson([
                'issuer' => url('/'),
                'authorization_endpoint' => route('passport.authorizations.authorize'),
                'token_endpoint' => route('passport.token'),
                'registration_endpoint' => route('mcp.oauth.register'),
                'response_types_supported' => ['code'],
                'code_challenge_methods_supported' => ['S256'],
                'grant_types_supported' => ['authorization_code', 'refresh_token'],
                'scopes_supported' => [Registrar::OAUTH_SCOPE],
            ]);
    }

    /**
     * Регистрация клиента (RFC 7591) — та самая «автоматическая», которую
     * выбирают в claude.ai, чтобы не заводить клиента руками.
     */
    public function test_a_connector_registers_itself(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $response->assertCreated()
            ->assertJson([
                'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'scope' => Registrar::OAUTH_SCOPE,
                // Публичный клиент: секрета у него нет, и подтверждает он себя
                // кодом-испытанием, а не паролем.
                'token_endpoint_auth_method' => 'none',
            ])
            ->assertJsonStructure(['client_id']);

        $this->assertDatabaseHas('oauth_clients', ['name' => 'Claude', 'secret' => null]);
    }

    /**
     * Чужой адрес возврата — отказ.
     *
     * Главная причина, по которой открытая регистрация остаётся безопасной:
     * заведись клиент с возвратом на чужой сервер, и уговорившему сотрудника
     * открыть ссылку согласия уехал бы код авторизации. Перечень разрешённых
     * доменов — в config/mcp.php.
     */
    public function test_a_stranger_cannot_register_a_client_that_redirects_to_itself(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'Не Claude',
            'redirect_uris' => ['https://example.com/callback'],
        ])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_redirect_uri']);

        $this->assertDatabaseCount('oauth_clients', 0);
    }

    /** С токеном и подтверждённым полномочием сервер отвечает. */
    public function test_a_token_with_the_scope_reaches_the_server(): void
    {
        Passport::actingAs($this->administrator(), [Registrar::OAUTH_SCOPE]);

        $this->postJson('/mcp', $this->handshake(), $this->headers('initialize'))
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'BISMAR CRM');
    }

    /**
     * Токен без полномочия `mcp:use` — не токен для этой двери.
     *
     * Полномочие ровно одно, и подтверждает его сотрудник на экране согласия:
     * токен, выданный под что-то другое, сюда не пускают.
     */
    public function test_a_token_without_the_scope_is_refused(): void
    {
        Passport::actingAs($this->administrator(), ['something-else']);

        $this->postJson('/mcp', $this->handshake(), $this->headers('initialize'))
            ->assertForbidden();
    }

    /**
     * Уволенный с живым токеном не работает.
     *
     * Увольнение гасит выданные токены, но проверка стоит и на входе: полагаться
     * на уборку значило бы держать дверь открытой ровно настолько, насколько
     * повезёт.
     */
    public function test_a_dismissed_employee_is_refused_even_with_a_live_token(): void
    {
        Passport::actingAs(
            User::factory()->dismissed()->create(),
            [Registrar::OAUTH_SCOPE],
        );

        $this->postJson('/mcp', $this->handshake(), $this->headers('initialize'))
            ->assertUnauthorized();
    }

    /**
     * Весь путь коннектора целиком — от регистрации до вызова инструмента.
     *
     * Самая важная проверка во всём разделе, и ценна она именно тем, что не
     * разобрана на части: каждый её шаг по отдельности проверен выше, но
     * подключиться коннектор сможет только если они сходятся друг с другом —
     * код-испытание с проверкой, полномочие из согласия с полномочием токена,
     * адрес возврата с заявленным при регистрации. Разойдись любая пара, и
     * claude.ai скажет «не удалось подключиться», не сказав, где именно.
     */
    public function test_the_whole_way_from_registration_to_a_tool_call(): void
    {
        $person = $this->administrator();
        $client = $this->connector();

        // Код-испытание: единственное, чем публичный клиент подтверждает, что
        // код обменивает он же, а не перехвативший.
        $verifier = str_repeat('a', 64);

        // 1. Сотрудник открывает экран согласия.
        $this->actingAs($person)
            ->get(route('passport.authorizations.authorize', $this->authorizeQuery($client, $verifier)))
            ->assertOk();

        $authToken = session('authToken');
        $this->assertIsString($authToken);

        // 2. Нажимает «Разрешить» — и его возвращают к коннектору с кодом.
        $approved = $this->actingAs($person)
            ->post(route('passport.authorizations.approve'), ['auth_token' => $authToken]);

        $approved->assertRedirectContains('https://claude.ai/api/mcp/auth_callback');

        $location = (string) $approved->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $returned);

        // Состояние возвращается тем же, каким пришло: по нему коннектор узнаёт
        // свой же запрос.
        $this->assertSame('state-from-the-connector', $returned['state'] ?? null);
        $this->assertArrayHasKey('code', $returned);

        // 3. Коннектор выменивает код на токен — на своей стороне, без cookie.
        $issued = $this->post(route('passport.token'), [
            'grant_type' => 'authorization_code',
            'client_id' => $client,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_verifier' => $verifier,
            'code' => $returned['code'],
        ])->assertOk();

        $access = (string) $issued->json('access_token');
        $refresh = (string) $issued->json('refresh_token');

        $this->assertNotSame('', $access);
        $this->assertNotSame('', $refresh);

        // 4. И работает этим токеном. Сессии у него нет — только заголовок.
        $this->flushSession();

        $this->postJson('/mcp', $this->handshake(), [
            ...$this->headers('initialize'),
            'Authorization' => 'Bearer '.$access,
        ])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'BISMAR CRM');

        // 5. Инструменты видны — те, что открыты этому человеку.
        $tools = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ], [
            ...$this->headers('tools/list'),
            'Authorization' => 'Bearer '.$access,
        ])->assertOk();

        $names = array_column((array) $tools->json('result.tools'), 'name');

        $this->assertContains('whoami', $names);
        $this->assertContains('staff_search', $names);

        // 6. А когда токен истечёт, коннектор продлит его сам — иначе сотруднику
        // пришлось бы подтверждать согласие каждые сутки.
        $this->post(route('passport.token'), [
            'grant_type' => 'refresh_token',
            'client_id' => $client,
            'refresh_token' => $refresh,
        ])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token']);
    }

    /* ---------- Когда доступ заканчивается ---------- */

    /**
     * Увольнение гасит выданные токены — и обновляющие тоже.
     *
     * Без второго коннектор немедленно выменял бы погашенный токен на свежий:
     * обновляющий живёт тридцать дней и спрашивает только себя.
     */
    public function test_dismissal_puts_out_the_tokens_of_the_connector(): void
    {
        $person = User::factory()->create();
        $token = $this->tokenFor($person);

        $this->actingAs($this->administrator())
            ->postJson(route('users.dismiss', $person))
            ->assertOk();

        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $token, 'revoked' => true]);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['access_token_id' => $token, 'revoked' => true]);
    }

    public function test_deleting_a_record_puts_out_its_tokens_too(): void
    {
        $person = User::factory()->dismissed()->create();
        $token = $this->tokenFor($person);

        $this->actingAs($this->superAdministrator())
            ->deleteJson(route('users.destroy', $person))
            ->assertNoContent();

        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $token, 'revoked' => true]);
    }

    /* ---------- Экран согласия ---------- */

    /**
     * Гостя ведут на вход, а не отказывают.
     *
     * Единственная страница приложения, которую отдаёт Laravel: открывает её
     * человек в браузере, и 401 показал бы ему пустую ошибку. Форма входа — в
     * Nuxt, поэтому возврат уезжает в её адрес (см. bootstrap/app.php).
     */
    public function test_a_guest_at_the_consent_screen_is_sent_to_sign_in(): void
    {
        $this->get(route('passport.authorizations.authorize', $this->authorizeQuery($this->connector())))
            ->assertRedirectContains('/login?redirect=')
            ->assertRedirectContains(rawurlencode('/oauth/authorize'));
    }

    /**
     * Сотруднику показывают, кто просит и куда его вернут.
     *
     * Второе — главное: имя клиента придумывает сам клиент при регистрации, и
     * «Claude» в заголовке не значит ничего, пока адрес возврата не ведёт к
     * Anthropic.
     */
    public function test_the_consent_screen_names_the_client_and_where_it_returns(): void
    {
        $person = $this->administrator();

        $this->actingAs($person)
            ->get(route('passport.authorizations.authorize', $this->authorizeQuery($this->connector())))
            ->assertOk()
            ->assertSee('Claude', escape: false)
            ->assertSee('claude.ai')
            ->assertSee($person->name)
            ->assertSee('Разрешить')
            ->assertSee('Отказать');
    }

    /** GET и DELETE на этом адресе не бывает — только POST. */
    public function test_the_server_speaks_only_post(): void
    {
        $this->get('/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
        $this->delete('/mcp')->assertStatus(405)->assertHeader('Allow', 'POST');
    }

    /**
     * Выданный этому человеку токен — с обновляющим при нём, как их и выдаёт
     * Passport.
     *
     * @return string номер токена
     */
    private function tokenFor(User $person): string
    {
        $token = Passport::token()->newQuery()->forceCreate([
            'id' => Str::random(80),
            'user_id' => $person->getKey(),
            'client_id' => $this->connector(),
            'scopes' => [Registrar::OAUTH_SCOPE],
            'revoked' => false,
            'expires_at' => now()->addDay(),
        ]);

        Passport::refreshToken()->newQuery()->forceCreate([
            'id' => Str::random(80),
            'access_token_id' => $token->getKey(),
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ]);

        return (string) $token->getKey();
    }

    /**
     * Зарегистрированный коннектор — тем же адресом, каким его заводит claude.ai.
     */
    private function connector(): string
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertCreated();

        return (string) $response->json('client_id');
    }

    /**
     * Запрос разрешения — как его собирает публичный клиент: код-испытание
     * вместо секрета (PKCE, S256).
     *
     * @return array<string, string>
     */
    private function authorizeQuery(string $clientId, string $verifier = 'a-verifier-long-enough-to-be-accepted-by-the-server'): array
    {
        return [
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => Registrar::OAUTH_SCOPE,
            'state' => 'state-from-the-connector',
            'code_challenge' => strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_'),
            'code_challenge_method' => 'S256',
        ];
    }

    /**
     * Рукопожатие, с которого начинается любой разговор по MCP.
     *
     * @return array<string, mixed>
     */
    private function handshake(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'tests', 'version' => '1.0'],
            ],
        ];
    }

    /**
     * Заголовки, которых сервер требует от запроса: согласованная версия
     * протокола и имя метода, совпадающее с телом.
     *
     * @return array<string, string>
     */
    private function headers(string $method): array
    {
        return [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-11-25',
            'Mcp-Method' => $method,
        ];
    }
}
