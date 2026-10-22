<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\Permission;
use App\Enums\SalesPlanKind;
use App\Models\Department;
use App\Models\SalesPerson;
use App\Models\SalesPlan;
use App\Support\Analytics\ClickHouse;
use App\Support\Sales\RaceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeClickHouse;
use Tests\TestCase;

/**
 * Битва отделов — табло для зала.
 *
 * Факт здесь нулевой: достоверность выручки принадлежит выгрузке. Проверяется
 * состав гонки и её мерка — кто в ней участвует и с чем сравнивается итог
 * магазина.
 */
final class RaceTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ClickHouse::class, FakeClickHouse::empty());
    }

    private function month(): CarbonImmutable
    {
        return CarbonImmutable::today()->startOfMonth();
    }

    private function report(): array
    {
        return app(RaceReport::class)->forMonth($this->month());
    }

    /** Табло открыто тому же, кому открыт дашборд. */
    public function test_the_board_needs_the_analytics_right(): void
    {
        $this->actingAs($this->userWith())
            ->getJson(route('analytics.race'))
            ->assertForbidden();

        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route('analytics.race'))
            ->assertOk();
    }

    /** В гонке участвуют те, кому поставлен план, — остальным не с чем. */
    public function test_only_planned_departments_run(): void
    {
        $running = Department::factory()->create(['parent_id' => 1]);
        $watching = Department::factory()->create(['parent_id' => 1]);

        SalesPerson::query()->create(['external_name' => 'Первый', 'department_id' => $running->id]);
        SalesPerson::query()->create(['external_name' => 'Второй', 'department_id' => $watching->id]);

        SalesPlan::query()->create([
            'period' => $this->month(),
            'kind' => SalesPlanKind::Department,
            'department_id' => $running->id,
            'revenue' => 1_000_000,
        ]);

        $names = array_column($this->report()['departments'], 'name');

        $this->assertContains($running->name, $names);
        $this->assertNotContains($watching->name, $names);
    }

    /**
     * Отдел с запланированным потомком сам не бежит.
     *
     * Гипермаркет против собственной «Электрики» — не гонка, а сложение с
     * самим собой: его полоса включала бы её целиком.
     */
    public function test_a_parent_steps_aside_for_its_planned_child(): void
    {
        $parent = Department::factory()->create(['parent_id' => 1]);
        $child = Department::factory()->create(['parent_id' => $parent->id]);

        foreach ([$parent, $child] as $department) {
            SalesPlan::query()->create([
                'period' => $this->month(),
                'kind' => SalesPlanKind::Department,
                'department_id' => $department->id,
                'revenue' => 1_000_000,
            ]);
        }

        $names = array_column($this->report()['departments'], 'name');

        $this->assertSame([$child->name], $names);
    }

    /**
     * План магазина — ровно сумма бегущих.
     *
     * Иначе план и факт считались бы о разных людях: витрина знает и ту
     * торговлю, что в гонке не участвует, и делить её на мерку участников
     * значит показать сто тридцать процентов там, где идут вровень.
     */
    public function test_the_store_plan_is_the_sum_of_the_runners(): void
    {
        $first = Department::factory()->create(['parent_id' => 1]);
        $second = Department::factory()->create(['parent_id' => 1]);

        // Третий отдел торгует, но плана ему не поставили: в сумму он не
        // входит — и его выручка не должна попасть в факт магазина.
        $outside = Department::factory()->create(['parent_id' => 1]);
        SalesPerson::query()->create(['external_name' => 'Вне гонки', 'department_id' => $outside->id]);

        foreach ([$first->id => 1_000_000, $second->id => 500_000] as $departmentId => $revenue) {
            SalesPlan::query()->create([
                'period' => $this->month(),
                'kind' => SalesPlanKind::Department,
                'department_id' => $departmentId,
                'revenue' => $revenue,
            ]);
        }

        $this->assertSame(1_500_000.0, $this->report()['store']['plan_month']);
    }

    /**
     * Без плана гонки нет, и табло говорит это прямо, а не показывает нули.
     */
    public function test_without_plans_there_is_no_race(): void
    {
        $report = $this->report();

        $this->assertSame([], $report['departments']);
        $this->assertNull($report['store']['plan_month']);
    }
}
