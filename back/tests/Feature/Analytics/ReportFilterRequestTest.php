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
 * Доходит ли выбранное в панели фильтров до самого запроса.
 *
 * Проверяется не отчёт, а дорога к нему: строка запроса → разбор → условие и
 * параметр в SQL. Дорога эта однажды уже обрывалась молча — списки уходили
 * повторяющимся ключом без скобок (`warehouses=Оптовый&warehouses=Розничный`),
 * PHP оставлял от них последнее значение строкой, правило `array` отвечало
 * отказом, и вкладка гасла на любом выборе склада. Ни один тест этого не
 * заметил: отчёты проверялись объектом фильтров, собранным в самом тесте, а
 * не тем, что приезжает из браузера.
 */
final class ReportFilterRequestTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    private FakeClickHouse $clickhouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clickhouse = FakeClickHouse::empty();
        $this->app->instance(ClickHouse::class, $this->clickhouse);
    }

    /**
     * Колонка витрины за каждым списком: перепутанная местами, она отфильтрует
     * не то, о чём спрашивали, и панель останется правдоподобно неверной.
     *
     * @return list<array{string, string}>
     */
    public static function lists(): array
    {
        return [
            ['channels', 'kanal'],
            ['warehouses', 'sklad'],
            ['managers', 'menedzher'],
            ['segments', 'segment_klienta'],
        ];
    }

    #[DataProvider('lists')]
    public function test_a_chosen_list_reaches_the_query(string $list, string $column): void
    {
        $chosen = ['Первое значение', 'Второе значение'];

        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route('analytics.sales', [$list => $chosen]))
            ->assertOk();

        $this->assertStringContainsString(
            "AND s.{$column} IN (:{$list})",
            $this->clickhouse->sql(),
        );

        // Значение уходит параметром, а не склейкой: подставленная строка в
        // условии открыла бы читателю любую таблицу сервера.
        $this->assertSame($chosen, $this->bindingFor($list));
    }

    /**
     * Одиночный выбор — тоже список.
     *
     * Так его присылает всякий, кто собирает ссылку руками, и так же его
     * разбирает PHP из строки без скобок. Отказ здесь означал бы, что фильтр
     * работает при двух выбранных значениях и не работает при одном.
     */
    public function test_a_single_value_is_taken_as_a_list_of_one(): void
    {
        // Ключ без скобок — ровно то, что PHP оставляет от повторяющегося
        // параметра, и то, как ссылку собирают руками.
        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route('analytics.sales').'?warehouses='.rawurlencode('Оптовый'))
            ->assertOk();

        $this->assertSame(['Оптовый'], $this->bindingFor('warehouses'));
    }

    /**
     * Возвраты уменьшают выручку, поэтому по умолчанию они в ней есть, и
     * условие появляется только когда их сняли.
     */
    public function test_returns_are_excluded_only_when_asked(): void
    {
        $person = $this->userWith(Permission::ViewAnalytics);

        $this->actingAs($person)->getJson(route('analytics.sales'))->assertOk();
        $this->assertStringNotContainsString('s.vozvrat = 0', $this->clickhouse->sql());

        $this->clickhouse->queries = [];

        $this->actingAs($person)
            ->getJson(route('analytics.sales', ['with_returns' => 0]))
            ->assertOk();

        $this->assertStringContainsString('s.vozvrat = 0', $this->clickhouse->sql());
    }

    /**
     * Шаг графика задаётся человеком, а не только длиной отрезка: недельный
     * срез иногда смотрят по неделям, чтобы сравнить его с соседним.
     */
    public function test_the_granularity_reaches_the_bucket(): void
    {
        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route('analytics.sales', ['preset' => 'month', 'granularity' => 'month']))
            ->assertOk()
            ->assertJsonPath('meta.period.granularity', 'month');

        $this->assertStringContainsString('toStartOfMonth(s.data)', $this->clickhouse->sql());
    }

    /** Список каждой вкладки отбирается одинаково — иначе однажды разойдётся. */
    #[DataProvider('sections')]
    public function test_every_section_filters_by_the_same_lists(string $route): void
    {
        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route($route, ['warehouses' => ['Оптовый']]))
            ->assertOk();

        $this->assertStringContainsString('AND s.sklad IN (:warehouses)', $this->clickhouse->sql());
        $this->assertSame(['Оптовый'], $this->bindingFor('warehouses'));
    }

    /**
     * @return list<array{string}>
     */
    public static function sections(): array
    {
        return [
            ['analytics.sales'],
            ['analytics.customers'],
            ['analytics.products'],
        ];
    }

    /**
     * Что ушло в запрос под этим именем.
     *
     * @return mixed
     */
    private function bindingFor(string $parameter)
    {
        foreach ($this->clickhouse->queries as $query) {
            if (array_key_exists($parameter, $query['bindings'])) {
                return $query['bindings'][$parameter];
            }
        }

        $this->fail("Параметр :{$parameter} не дошёл ни до одного запроса.");
    }
}
