<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\Permission;
use App\Support\Analytics\ClickHouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeClickHouse;
use Tests\TestCase;

/**
 * Продажи по сотрудникам.
 *
 * Проверяется то, что отличает эту вкладку от рейтинга по менеджеру на вкладке
 * продаж, — и то, что сломается молча. Достоверность самих цифр принадлежит
 * выгрузке; здесь сервер аналитики подменён пустым.
 */
final class EmployeeSalesTest extends TestCase
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
     * Сотрудник спрашивается одним полем, собранным из двух.
     *
     * Витрина держит «кто продал» в двух местах: в чеке ККМ заполнен продавец,
     * в накладной — ответственный. Рейтинг по любому из них поодиночке теряет
     * половину торговли, и теряет правдоподобно: список остаётся непустым, в
     * нём просто нет розницы. Поэтому отчёт обязан спрашивать `sotrudnik` —
     * объединение обоих (см. Mart::sales()), а не одно из полей.
     */
    public function test_the_report_asks_for_the_merged_person(): void
    {
        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route('analytics.employees'))
            ->assertOk();

        $sql = $this->clickhouse->sql();

        $this->assertStringContainsString('s.sotrudnik', $sql);
        $this->assertStringNotContainsString('s.menedzher', $sql);
        $this->assertStringNotContainsString('s.prodavec', $sql);
    }

    /**
     * Строки без человека не приписываются никому.
     *
     * Их доли процента, но «ничьё» и «ноль» — разные вещи: приписанные
     * последнему попавшемуся имени, они сделали бы его рейтинг неверным, а
     * выброшенные молча — развели бы итог вкладки с итогом продаж без
     * объяснения. Поэтому из рейтинга они исключены, а в сводке стоят своей
     * величиной.
     */
    public function test_sales_without_a_person_are_kept_apart(): void
    {
        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route('analytics.employees'))
            ->assertOk()
            ->assertJsonPath('data.summary.current.revenue_unattributed', 0);

        $this->assertStringContainsString("s.sotrudnik != ''", $this->clickhouse->sql());
    }

    /**
     * Пустой период не роняет вкладку.
     *
     * Средние здесь делятся на число людей и число заказов, и в периоде, в
     * котором не было ни одной продажи, оба знаменателя — ноль.
     */
    public function test_an_empty_period_gives_zeroes_and_not_an_error(): void
    {
        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route('analytics.employees', ['preset' => 'today']))
            ->assertOk()
            ->assertJsonPath('data.summary.current.people', 0)
            ->assertJsonPath('data.summary.current.margin', 0)
            ->assertJsonPath('data.summary.current.average_order', 0)
            ->assertJsonPath('data.summary.current.revenue_per_person', 0)
            ->assertJsonPath('data.ranking', []);
    }

    /**
     * Рейтинг не отдаётся длиннее, чем отчёт обещает.
     *
     * Вкладка просит список целиком, а не верхушку, и предел здесь —
     * единственное, что стоит между «всеми, кто торговал» и выгрузкой всей
     * витрины по одному адресу.
     */
    public function test_the_ranking_refuses_a_limit_beyond_the_ceiling(): void
    {
        $beyond = (int) config('analytics.max_top_limit') + 1;

        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route('analytics.employees', ['limit' => $beyond]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');
    }
}
