<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\Permission;
use App\Support\Analytics\ClickHouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeClickHouse;
use Tests\TestCase;

/**
 * Кого пускают к торговым цифрам.
 *
 * Выручка, себестоимость и долги контрагентов — не то, что показывают всякому,
 * кто завёл учётную запись ради базы знаний. Раздел закрыт отдельным правом, и
 * проверяется это на каждом маршруте, а не только на первом: разделы дашборда
 * открываются по прямой ссылке.
 */
final class AnalyticsAccessTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Живой сервер аналитики тестам не нужен: проверяются права, а не
        // содержимое выгрузки.
        $this->app->instance(ClickHouse::class, FakeClickHouse::empty());
    }

    /**
     * @return list<array{string}>
     */
    public static function routes(): array
    {
        return [
            ['analytics.directory'],
            ['analytics.sales'],
            ['analytics.customers'],
            ['analytics.products'],
        ];
    }

    #[DataProvider('routes')]
    public function test_a_guest_is_turned_away(string $route): void
    {
        $this->getJson(route($route))->assertUnauthorized();
    }

    #[DataProvider('routes')]
    public function test_a_signed_in_person_without_the_right_is_refused(string $route): void
    {
        // Право на базу знаний к торговым цифрам отношения не имеет.
        $this->actingAs($this->learner())
            ->getJson(route($route))
            ->assertForbidden();
    }

    #[DataProvider('routes')]
    public function test_the_right_opens_every_section(string $route): void
    {
        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route($route))
            ->assertOk();
    }

    /**
     * @return list<array{string, string}>
     */
    public static function breakdowns(): array
    {
        return [
            ['analytics.sales.breakdown', 'manager'],
            ['analytics.products.breakdown', 'brand'],
        ];
    }

    #[DataProvider('breakdowns')]
    public function test_a_breakdown_answers_to_the_same_right(string $route, string $dimension): void
    {
        $this->actingAs($this->learner())
            ->getJson(route($route, ['dimension' => $dimension]))
            ->assertForbidden();

        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route($route, ['dimension' => $dimension]))
            ->assertOk();
    }

    public function test_an_unknown_breakdown_is_refused_rather_than_reaching_the_query(): void
    {
        // Имя колонки попадает в SQL склейкой, поэтому список закрыт.
        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            // Маршрут не совпадёт вовсе: разрез сверяется со списком там же,
            // где объявлен, и до отчёта чужое имя не доходит.
            ->getJson('/api/analytics/sales/breakdown/'.urlencode('tovar) FROM system.tables --'))
            ->assertNotFound();
    }

    public function test_an_administrator_passes_without_the_right_being_ticked(): void
    {
        // Gate::before пропускает администратора целиком — проверка на то, что
        // раздел не закрыт чем-то помимо права.
        $this->actingAs($this->administrator())
            ->getJson(route('analytics.sales'))
            ->assertOk();
    }

    public function test_an_unavailable_server_answers_503_and_not_a_crash(): void
    {
        $this->app->instance(ClickHouse::class, FakeClickHouse::down());

        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route('analytics.sales'))
            ->assertStatus(503)
            ->assertJsonPath('message', 'Сервер аналитики недоступен.');
    }
}
