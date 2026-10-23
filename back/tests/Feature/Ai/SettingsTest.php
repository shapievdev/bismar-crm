<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Anthropic\RequestOptions;
use App\Enums\AiAuthScheme;
use App\Models\AiSetting;
use App\Models\Group;
use App\Models\Regulation;
use App\Support\Ai\ModelSettings;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeAnthropicTransport;
use Tests\TestCase;

/**
 * Кто настраивает консультанта и что при этом видно.
 *
 * Ключ здесь — платёжный: по нему тратятся деньги компании. Поэтому проверок
 * две — кого пускают к настройкам и что ключ не отдаётся наружу никому.
 */
final class SettingsTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_a_superadmin_saves_the_model_and_the_endpoint(): void
    {
        $this->actingAs($this->superAdministrator())
            ->putJson(route('ai.settings.update'), [
                'model' => 'gpt-4o-mini',
                'base_url' => 'https://api.aitunnel.ru',
                'api_key' => 'sk-secret-value',
                'auth_scheme' => AiAuthScheme::Bearer->value,
                'max_tokens' => 2048,
            ])
            ->assertOk()
            ->assertJsonPath('data.model', 'gpt-4o-mini')
            ->assertJsonPath('data.effective.model', 'gpt-4o-mini');

        $settings = ModelSettings::current();

        $this->assertSame('gpt-4o-mini', $settings->model());
        $this->assertSame('sk-secret-value', $settings->key());
        $this->assertSame(AiAuthScheme::Bearer, $settings->authScheme());
    }

    /**
     * Администратор проходит Gate::before и получил бы вместе со всеми правами
     * ещё и платёжный ключ. Настройки модели — единственное, что ему не
     * принадлежит, и запрещено это явной проверкой уровня, а не политикой.
     */
    public function test_an_administrator_is_refused(): void
    {
        $this->actingAs($this->administrator())
            ->getJson(route('ai.settings.show'))
            ->assertForbidden();

        $this->actingAs($this->administrator())
            ->putJson(route('ai.settings.update'), [
                'auth_scheme' => AiAuthScheme::Bearer->value,
                'model' => 'что-нибудь-своё',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('ai_settings', 0);
    }

    public function test_a_plain_user_is_refused(): void
    {
        $this->actingAs($this->learner())
            ->getJson(route('ai.settings.show'))
            ->assertForbidden();
    }

    /** Ключ не возвращается никогда — только подсказка из последних знаков. */
    public function test_the_key_is_never_returned(): void
    {
        AiSetting::query()->create([
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-secret-tail',
            'auth_scheme' => AiAuthScheme::Bearer,
        ]);

        $response = $this->actingAs($this->superAdministrator())
            ->getJson(route('ai.settings.show'))
            ->assertOk()
            ->assertJsonPath('data.key_hint', '…tail')
            ->assertJsonPath('data.has_key', true);

        $this->assertStringNotContainsString('sk-secret-tail', $response->getContent());
    }

    /**
     * Форма не показывает ключ, поэтому и не может его прислать. Сохранение
     * любой другой правки не должно стирать рабочий ключ.
     */
    public function test_saving_without_a_key_keeps_the_stored_one(): void
    {
        AiSetting::query()->create([
            'model' => 'claude-haiku-4-5',
            'api_key' => 'sk-stored',
            'auth_scheme' => AiAuthScheme::Bearer,
        ]);

        $this->actingAs($this->superAdministrator())
            ->putJson(route('ai.settings.update'), [
                'model' => 'gpt-4o-mini',
                'api_key' => '',
                'auth_scheme' => AiAuthScheme::Bearer->value,
            ])
            ->assertOk();

        $this->assertSame('sk-stored', ModelSettings::current()->key());
        $this->assertSame('gpt-4o-mini', ModelSettings::current()->model());
    }

    /** Незаполненное поле означает «взять из .env», а не «пусто». */
    public function test_an_empty_field_falls_back_to_the_environment(): void
    {
        config(['ai.model' => 'claude-haiku-4-5', 'ai.auth_token' => 'sk-from-env']);

        AiSetting::query()->create(['model' => null, 'auth_scheme' => AiAuthScheme::Bearer]);

        $settings = ModelSettings::current();

        $this->assertSame('claude-haiku-4-5', $settings->model());
        $this->assertSame('sk-from-env', $settings->key());
    }

    /** Лишний слэш в адресе дал бы прокси запрос на //v1/messages. */
    public function test_a_trailing_slash_is_trimmed_from_the_endpoint(): void
    {
        AiSetting::query()->create([
            'base_url' => 'https://api.aitunnel.ru/',
            'auth_scheme' => AiAuthScheme::Bearer,
        ]);

        $this->assertSame('https://api.aitunnel.ru', ModelSettings::current()->baseUrl());
    }

    public function test_a_malformed_endpoint_is_rejected(): void
    {
        $this->actingAs($this->superAdministrator())
            ->putJson(route('ai.settings.update'), [
                'base_url' => 'не адрес',
                'auth_scheme' => AiAuthScheme::Bearer->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('base_url');
    }

    /* ---------- Ключ из чужого дампа ---------- */

    /**
     * Ключ, зашифрованный другим APP_KEY, считается незаданным.
     *
     * База уезжает дампом на ноутбуки и на чужие стенды, а ключ в ней
     * зашифрован APP_KEY той системы, где его вводили. Прочитать его там нечем,
     * и это именно «не задан»: дальше в дело идут переменные окружения.
     */
    public function test_a_key_encrypted_by_another_app_key_counts_as_unset(): void
    {
        config(['ai.auth_token' => 'sk-from-env', 'ai.api_key' => null]);

        $this->storeKeyFromAnotherStand();

        $settings = ModelSettings::current();

        $this->assertSame('sk-from-env', $settings->key());
        $this->assertNull(AiSetting::current()->keyHint());
        $this->assertTrue(AiSetting::current()->hasUnreadableKey());
    }

    /**
     * Нечитаемый ключ не валит запрос, который лишь спрашивает «настроен ли
     * консультант».
     *
     * Спрашивают об этом на каждом сохранении материала и его версии
     * (MaterialVersionObserver → EmbedRegulation::dispatchIfConfigured), и
     * исключение оттуда отвечало пятисотой на заведение версии документа —
     * отказом без единого слова о причине.
     */
    public function test_an_unreadable_key_does_not_break_saving_a_version(): void
    {
        config(['ai.auth_token' => null, 'ai.api_key' => null]);

        $this->storeKeyFromAnotherStand();

        $document = Regulation::factory()->published()->create();
        $group = Group::factory()->create(['name' => 'Розница']);

        $this->actingAs($this->superAdministrator())
            ->postJson(route('lms.documents.versions.store', $document), [
                'name' => 'Для розницы',
                'groups' => [$group->id],
                'departments' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Для розницы');

        $this->assertFalse(ModelSettings::current()->isConfigured());
    }

    /**
     * Ключ с чужого стенда не мешает сохранить новый.
     *
     * База приезжает дампом на ноутбуки, и ключ в ней зашифрован чужим
     * `APP_KEY`. Сохранение такой строки падало с «The MAC is invalid»: Eloquent
     * перед записью сравнивает новое значение со старым, а сравнить шифр он
     * может, только расшифровав его. Получался тупик — ключ нельзя было
     * заменить именно там, где он и не работает.
     */
    public function test_a_key_from_another_stand_does_not_block_saving_a_new_one(): void
    {
        $this->storeKeyFromAnotherStand();

        $this->actingAs($this->superAdministrator())
            ->putJson(route('ai.settings.update'), [
                'model' => 'claude-haiku-4.5',
                'api_key' => 'sk-aitunnel-svezhij',
                'auth_scheme' => AiAuthScheme::Bearer->value,
            ])
            ->assertOk();

        $this->assertSame('sk-aitunnel-svezhij', AiSetting::current()->readableKey());
    }

    /* ---------- Проверка связи ---------- */

    /**
     * Проверяется набранное в форме, а не сохранённое.
     *
     * Раньше это была ловушка, и попадались в неё первым же действием: вставил
     * свежий ключ, нажал «Проверить связь» — и получил отказ по старому,
     * потому что новый ещё не сохранён. Отказ при этом обвинял ровно тот ключ,
     * который только что вставили и который на самом деле хорош.
     */
    public function test_the_connection_is_checked_with_what_was_typed(): void
    {
        $this->storeKeyFromAnotherStand();

        $transport = $this->fakeModel();

        $this->actingAs($this->superAdministrator())
            ->postJson(route('ai.settings.test'), [
                'model' => 'claude-haiku-4.5',
                'api_key' => 'sk-aitunnel-svezhij',
            ])
            ->assertOk();

        // Спросили ту модель, которую набрали, а не ту, что лежит в базе.
        $this->assertSame('claude-haiku-4.5', $transport->payload()['model'] ?? null);
    }

    /**
     * Пустое поле ключа значит «тем, что уже в базе».
     *
     * Форма ключ не показывает, и пустым он приезжает всегда, когда его не
     * меняли. Понять это как «проверь без ключа» значило бы отвечать отказом
     * на каждую проверку, в которой трогали только имя модели.
     */
    public function test_an_empty_key_means_the_stored_one(): void
    {
        AiSetting::query()->create([
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-sohranjonnyj',
            'auth_scheme' => AiAuthScheme::Bearer,
        ]);

        $transport = $this->fakeModel();

        $this->actingAs($this->superAdministrator())
            ->postJson(route('ai.settings.test'), ['model' => 'claude-haiku-4.5'])
            ->assertOk();

        $this->assertSame('claude-haiku-4.5', $transport->payload()['model'] ?? null);
    }

    /** Ключа нет нигде — отказ словами, а не запрос без авторизации. */
    public function test_no_key_anywhere_is_refused_before_asking(): void
    {
        config()->set('ai.api_key', null);
        config()->set('ai.auth_token', null);

        $this->actingAs($this->superAdministrator())
            ->postJson(route('ai.settings.test'), ['model' => 'claude-haiku-4.5'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ключ не задан — ни в поле выше, ни в настройках, ни в переменных окружения.');
    }

    /** Проверка ничего не сохраняет: опечатка не должна ломать работающего. */
    public function test_checking_does_not_save_anything(): void
    {
        AiSetting::query()->create([
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-sohranjonnyj',
            'auth_scheme' => AiAuthScheme::Bearer,
        ]);

        $this->fakeModel();

        $this->actingAs($this->superAdministrator())
            ->postJson(route('ai.settings.test'), ['model' => 'opechatka-v-imeni'])
            ->assertOk();

        $this->assertSame('gpt-4o-mini', AiSetting::current()->model);
    }

    private function fakeModel(): FakeAnthropicTransport
    {
        $transport = FakeAnthropicTransport::replying('Связь.');

        /*
         * Клиента проверка связи собирает сама — из набранного в форме, — и
         * готового из контейнера не берёт. Поэтому подменяется не он, а
         * настройки запроса под ним: ключ и адрес при этом остаются теми,
         * которые мы и хотим проверить.
         */
        $this->app->instance(RequestOptions::class, RequestOptions::with(
            transporter: $transport,
            maxRetries: 0,
        ));

        return $transport;
    }

    /** Строка ключа, какой она приезжает с чужого стенда: шифр не наш. */
    private function storeKeyFromAnotherStand(): void
    {
        $stranger = new Encrypter(random_bytes(32), 'aes-256-cbc');

        AiSetting::query()->create(['auth_scheme' => AiAuthScheme::Bearer]);

        AiSetting::query()->update(['api_key' => $stranger->encrypt('sk-secret-value')]);
    }
}
