<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\GoogleSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Связка с Google настраивается в приложении.
 *
 * До этого её заводили в переменных окружения, то есть руками того, у кого есть
 * доступ к серверу. Настраивает компанию не он, и ждать его ради двух строк —
 * ровно та работа, которой здесь быть не должно.
 */
final class GoogleSettingsTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_an_administrator_sets_the_keys_up(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->putJson(route('integrations.google.update'), [
                'client_id' => '123.apps.googleusercontent.com',
                'api_key' => 'AIzaKeyForPicker',
            ])
            ->assertOk()
            ->assertJsonPath('data.client_id', '123.apps.googleusercontent.com')
            ->assertJsonPath('data.is_configured', true)
            ->assertJsonPath('data.updated_by', $administrator->name);

        $this->assertSame(1, GoogleSetting::query()->count());
    }

    /**
     * Наполовину заполненная настройка — не настройка: с одним значением окно
     * Google не открыть, а кнопка, ведущая к ошибке, хуже отсутствующей.
     */
    public function test_one_value_alone_does_not_count_as_configured(): void
    {
        $this->actingAs($this->administrator())
            ->putJson(route('integrations.google.update'), [
                'client_id' => '123.apps.googleusercontent.com',
                'api_key' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_configured', false);
    }

    /**
     * Читает всякий, кто вошёл: окно выбора файла открывается в его браузере, и
     * без этих значений оно не откроется. Тайны в них нет — ограничивает их
     * список источников в Google Cloud.
     */
    public function test_any_employee_reads_what_the_picker_needs(): void
    {
        $this->actingAs($this->administrator())
            ->putJson(route('integrations.google.update'), [
                'client_id' => '123.apps.googleusercontent.com',
                'api_key' => 'AIzaKeyForPicker',
            ])
            ->assertOk();

        $this->actingAs($this->learner())
            ->getJson(route('integrations.google.show'))
            ->assertOk()
            ->assertJsonPath('data.effective.client_id', '123.apps.googleusercontent.com')
            ->assertJsonPath('data.effective.api_key', 'AIzaKeyForPicker')
            ->assertJsonPath('data.is_configured', true);
    }

    /**
     * Секрет OAuth-клиента в поле ключа API не принимается.
     *
     * Ошибка лёгкая — в консоли Google оба значения лежат у одного клиента, —
     * а цена высокая: сохранённое здесь уезжает в браузер каждому вошедшему, и
     * секрет перестал бы быть секретом (случилось 2026-09-03).
     */
    public function test_a_client_secret_is_refused_where_the_api_key_belongs(): void
    {
        $this->actingAs($this->administrator())
            ->putJson(route('integrations.google.update'), [
                'client_id' => '123.apps.googleusercontent.com',
                'api_key' => 'GOCSPX-8fLkQ2mNvR7tYuIoP1aSdFgH',
            ])
            ->assertJsonValidationErrorFor('api_key');

        $this->assertSame(0, GoogleSetting::query()->count());
    }

    /** Настроить интеграцию — решение о компании, а не право с галочкой. */
    public function test_an_ordinary_employee_cannot_change_the_keys(): void
    {
        $this->actingAs($this->learner())
            ->putJson(route('integrations.google.update'), [
                'client_id' => 'чужой',
                'api_key' => 'чужой',
            ])
            ->assertForbidden();

        $this->assertSame(0, GoogleSetting::query()->count());
    }

    /**
     * Пока в настройках пусто, работают переменные окружения: у кого ключи уже
     * прописаны на сервере, ничего не ломается.
     */
    public function test_the_environment_still_works_while_the_settings_are_empty(): void
    {
        config([
            'services.google.client_id' => '456.apps.googleusercontent.com',
            'services.google.api_key' => 'AIzaFromEnv',
        ]);

        $this->actingAs($this->learner())
            ->getJson(route('integrations.google.show'))
            ->assertOk()
            ->assertJsonPath('data.client_id', null)
            ->assertJsonPath('data.effective.client_id', '456.apps.googleusercontent.com')
            ->assertJsonPath('data.is_configured', true);
    }

    /** Заполненное в форме перекрывает окружение — по полю, а не целиком. */
    public function test_the_settings_win_over_the_environment(): void
    {
        config(['services.google.client_id' => '456.apps.googleusercontent.com']);

        $this->actingAs($this->administrator())
            ->putJson(route('integrations.google.update'), [
                'client_id' => '789.apps.googleusercontent.com',
                'api_key' => 'AIzaKeyForPicker',
            ])
            ->assertOk()
            ->assertJsonPath('data.effective.client_id', '789.apps.googleusercontent.com');
    }
}
