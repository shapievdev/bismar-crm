<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\Permission;
use App\Enums\SalesPlanKind;
use App\Models\Department;
use App\Models\SalesPerson;
use App\Models\SalesPlan;
use App\Support\Analytics\ClickHouse;
use App\Support\Sales\DepartmentReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeClickHouse;
use Tests\TestCase;

/**
 * Планы продаж и отчёт план-факт.
 *
 * Факт здесь всюду нулевой: сервер аналитики подменён пустым, и достоверность
 * выручки принадлежит выгрузке, а не этим тестам. Проверяется то, что
 * принадлежит приложению, — как план находится, складывается и делится на дни.
 */
final class DepartmentPlanTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ClickHouse::class, FakeClickHouse::empty());
    }

    /** Ставить планы вправе не тот, кому просто показали цифры. */
    public function test_setting_a_plan_needs_its_own_right(): void
    {
        $department = Department::factory()->create(['parent_id' => 1]);

        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->putJson(route('sales.plans.update'), [
                'period' => '2026-10',
                'kind' => 'department',
                'department_id' => $department->id,
                'revenue' => 1000,
            ])
            ->assertForbidden();
    }

    /** План на месяц у цели один: второй переписывает первый, а не ложится рядом. */
    public function test_a_plan_is_rewritten_not_duplicated(): void
    {
        $department = Department::factory()->create(['parent_id' => 1]);
        $person = $this->userWith(Permission::ManageSalesPlans);

        foreach ([1_000_000, 1_500_000] as $amount) {
            $this->actingAs($person)
                ->putJson(route('sales.plans.update'), [
                    'period' => '2026-10',
                    'kind' => 'department',
                    'department_id' => $department->id,
                    'revenue' => $amount,
                ])
                ->assertOk();
        }

        $this->assertSame(1, SalesPlan::query()->count());
        $this->assertSame('1500000.00', SalesPlan::query()->sole()->revenue);
    }

    /** Личный план ставится на продавца, а не на отдел. */
    public function test_a_personal_plan_without_a_seller_is_refused(): void
    {
        $this->actingAs($this->userWith(Permission::ManageSalesPlans))
            ->putJson(route('sales.plans.update'), [
                'period' => '2026-10',
                'kind' => 'person',
                'revenue' => 500,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sales_person_id');
    }

    /**
     * Норматив подставляется тому, кому личного плана не ставили, — в том
     * числе новичку, которого завели вчера.
     */
    public function test_the_department_norm_stands_in_for_a_missing_personal_plan(): void
    {
        $department = Department::factory()->create(['parent_id' => 1]);

        $withPlan = SalesPerson::query()->create([
            'external_name' => 'ЛКМ Первый',
            'department_id' => $department->id,
        ]);

        SalesPerson::query()->create([
            'external_name' => 'ЛКМ Второй',
            'department_id' => $department->id,
        ]);

        $month = CarbonImmutable::parse('2026-09-01');

        SalesPlan::query()->create([
            'period' => $month,
            'kind' => SalesPlanKind::Member,
            'department_id' => $department->id,
            'revenue' => 800_000,
        ]);

        SalesPlan::query()->create([
            'period' => $month,
            'kind' => SalesPlanKind::Person,
            'sales_person_id' => $withPlan->id,
            'revenue' => 2_000_000,
        ]);

        $sellers = collect($this->report()->forMonth($month)['departments'])
            ->firstWhere('id', $department->id)['sellers'];

        $byName = collect($sellers)->keyBy('external_name');

        $this->assertSame(2_000_000.0, $byName['ЛКМ Первый']['plan']);
        $this->assertSame('person', $byName['ЛКМ Первый']['plan_source']);

        $this->assertSame(800_000.0, $byName['ЛКМ Второй']['plan']);
        $this->assertSame('member', $byName['ЛКМ Второй']['plan_source']);
    }

    /**
     * Выручка отдела — со всеми, кто под ним.
     *
     * Продавцов сопоставляют с нижними отделами, а спрашивают с верхних, и
     * отдел, считающий только своих, показывал бы ноль там, где торгуют пять
     * подчинённых отделов.
     */
    public function test_a_parent_counts_what_its_children_sold(): void
    {
        $parent = Department::factory()->create(['parent_id' => 1]);
        $child = Department::factory()->create(['parent_id' => $parent->id]);

        SalesPerson::query()->create(['external_name' => 'Продавец', 'department_id' => $child->id]);

        $month = CarbonImmutable::parse('2026-09-01');

        SalesPlan::query()->create([
            'period' => $month,
            'kind' => SalesPlanKind::Department,
            'department_id' => $parent->id,
            'revenue' => 1_000_000,
        ]);

        $rows = collect($this->report()->forMonth($month)['departments']);

        $this->assertNotNull($rows->firstWhere('id', $parent->id));
        $this->assertNotNull($rows->firstWhere('id', $child->id));
    }

    /**
     * Темп закрытого месяца — это весь план.
     *
     * А текущего — его доля по дням: факт шестнадцатого числа против плана на
     * все тридцать один всегда показывал бы отставание, которого нет.
     */
    public function test_a_closed_month_asks_for_the_whole_plan(): void
    {
        $department = Department::factory()->create(['parent_id' => 1]);
        $month = CarbonImmutable::today()->startOfMonth()->subMonth();

        SalesPlan::query()->create([
            'period' => $month,
            'kind' => SalesPlanKind::Department,
            'department_id' => $department->id,
            'revenue' => 3_100_000,
        ]);

        $report = $this->report()->forMonth($month);
        $row = collect($report['departments'])->firstWhere('id', $department->id);

        $this->assertFalse($report['period']['is_current']);
        $this->assertSame($report['period']['days_in_month'], $report['period']['days_elapsed']);
        $this->assertSame(3_100_000.0, $row['pace']);
    }

    public function test_a_running_month_asks_only_for_the_days_gone_by(): void
    {
        $department = Department::factory()->create(['parent_id' => 1]);
        $month = CarbonImmutable::today()->startOfMonth();

        SalesPlan::query()->create([
            'period' => $month,
            'kind' => SalesPlanKind::Department,
            'department_id' => $department->id,
            'revenue' => 3_100_000,
        ]);

        $report = $this->report()->forMonth($month);
        $row = collect($report['departments'])->firstWhere('id', $department->id);

        $expected = round(3_100_000 * $report['period']['days_elapsed'] / $report['period']['days_in_month'], 2);

        $this->assertTrue($report['period']['is_current']);
        $this->assertSame($expected, $row['pace']);
        $this->assertLessThanOrEqual(3_100_000.0, $row['pace']);
    }

    /**
     * Выполнение по компании считается от выручки тех же отделов, которым план
     * поставлен, а не от всей.
     *
     * Иначе две поставленные мерки делили бы выручку всей компании и показывали
     * бы двести процентов там, где не выполнено ничего.
     */
    public function test_the_company_total_compares_like_with_like(): void
    {
        $department = Department::factory()->create(['parent_id' => 1]);
        $month = CarbonImmutable::parse('2026-09-01');

        SalesPlan::query()->create([
            'period' => $month,
            'kind' => SalesPlanKind::Department,
            'department_id' => $department->id,
            'revenue' => 1_000_000,
        ]);

        $totals = $this->report()->forMonth($month)['totals'];

        $this->assertSame(1_000_000.0, $totals['plan']);
        $this->assertSame(0.0, $totals['planned_revenue']);
        $this->assertSame(0.0, $totals['completion']);
    }

    private function report(): DepartmentReport
    {
        return app(DepartmentReport::class);
    }
}
