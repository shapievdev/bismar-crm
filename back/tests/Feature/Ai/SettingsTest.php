<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiAuthScheme;
use App\Models\AiSetting;
use App\Support\Ai\ModelSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
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
}
