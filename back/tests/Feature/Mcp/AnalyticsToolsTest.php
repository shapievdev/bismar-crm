<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Enums\Permission;
use App\Mcp\Servers\Bismar;
use App\Mcp\Tools\Analytics\AnalyticsDirectory;
use App\Mcp\Tools\Analytics\CustomerReportTool;
use App\Mcp\Tools\Analytics\DepartmentPlan;
use App\Mcp\Tools\Analytics\EmployeeSales;
use App\Mcp\Tools\Analytics\ProductReportTool;
use App\Mcp\Tools\Analytics\SalesBreakdown;
use App\Mcp\Tools\Analytics\SalesReportTool;
use App\Mcp\Tools\Analytics\StaffReportTool;
use App\Mcp\Tools\Sales\ListSellers;
use App\Mcp\Tools\Sales\MapSeller;
use App\Mcp\Tools\Sales\SalesPlans;
use App\Mcp\Tools\Sales\SetSalesPlan;
use App\Models\Department;
use App\Models\SalesPerson;
use App\Models\SalesPlan;
use App\Models\User;
use App\Support\Analytics\ClickHouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeClickHouse;
use Tests\TestCase;

/**
 * Аналитика и планы через коннектор.
 *
 * Цифры здесь не проверяются: они принадлежат витрине, и подделывать их значило
 * бы проверять собственную подделку (см. Tests\Support\FakeClickHouse).
 * Проверяется то, что принадлежит платформе: кому инструменты видны, как
 * разбирается срез, что происходит при недоступном сервере и что планы
 * ставятся теми же правилами, что с экрана.
 */
final class AnalyticsToolsTest extends TestCase
{
    use MakesUsers, RefreshDatabase;

    /* ---------- Права ---------- */

    public function test_analytics_is_offered_only_to_those_who_may_read_it(): void
    {
        Bismar::actingAs($this->userWith(Permission::ViewCourses))
            ->tools()
            ->assertNotRegistered([
                AnalyticsDirectory::class,
                SalesReportTool::class,
                SalesBreakdown::class,
                ProductReportTool::class,
                CustomerReportTool::class,
                EmployeeSales::class,
                DepartmentPlan::class,
            ]);

        Bismar::actingAs($this->userWith(Permission::ViewAnalytics))
            ->tools()
            ->assertRegistered([
                AnalyticsDirectory::class,
                SalesReportTool::class,
                EmployeeSales::class,
                DepartmentPlan::class,
            ])
            // Читать выручку и решать, какой она должна быть, — разные
            // полномочия.
            ->assertNotRegistered([ListSellers::class, SetSalesPlan::class]);
    }

    public function test_plans_answer_to_their_own_permission(): void
    {
        Bismar::actingAs($this->userWith(Permission::ManageSalesPlans))
            ->tools()
            ->assertRegistered([ListSellers::class, MapSeller::class, SalesPlans::class, SetSalesPlan::class]);
    }

    /**
     * Аналитика штата — своё право, и она не следует из торговой: это
     * персональные данные.
     */
    public function test_the_staff_report_is_separate_from_the_trade_one(): void
    {
        Bismar::actingAs($this->userWith(Permission::ViewAnalytics))
            ->tools()
            ->assertNotRegistered(StaffReportTool::class);

        Bismar::actingAs($this->userWith(Permission::ViewWholeStaffReport))
            ->tools()
            ->assertRegistered(StaffReportTool::class);
    }

    /* ---------- Разбор среза ---------- */

    public function test_the_slice_comes_back_with_the_numbers(): void
    {
        $this->app->instance(ClickHouse::class, FakeClickHouse::empty());

        Bismar::actingAs($this->userWith(Permission::ViewAnalytics))
            ->tool(SalesReportTool::class, [
                'from' => '2026-09-01',
                'to' => '2026-09-30',
                'warehouses' => ['Основной'],
                'sections' => ['channels'],
            ])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('slice.period.from', '2026-09-01')
                ->where('slice.period.to', '2026-09-30')
                ->where('slice.warehouses', ['Основной'])
                // Возвраты входят, пока их явно не исключили.
                ->where('slice.with_returns', true)
                ->has('summary')
                ->has('channels')
                ->etc());
    }

    public function test_an_upside_down_period_is_refused_in_words(): void
    {
        $this->app->instance(ClickHouse::class, FakeClickHouse::empty());

        Bismar::actingAs($this->userWith(Permission::ViewAnalytics))
            ->tool(SalesReportTool::class, ['from' => '2026-09-30', 'to' => '2026-09-01'])
            ->assertHasErrors(['Конец периода не может быть раньше его начала.']);
    }

    /**
     * Неизвестный разрез не доходит до запроса: имя колонки попадает в SQL
     * склейкой, и отказ должен случиться раньше.
     */
    public function test_an_unknown_dimension_is_refused_and_the_known_ones_are_listed(): void
    {
        $this->app->instance(ClickHouse::class, FakeClickHouse::empty());

        $response = Bismar::actingAs($this->userWith(Permission::ViewAnalytics))
            ->tool(SalesBreakdown::class, ['dimension' => 'sklad; drop table'])
            ->assertHasErrors();

        $response->assertSee('Неизвестный разрез');
    }

    /**
     * Сервер аналитики стоит на сервере компании и доезжает через туннель:
     * «не ответил» — обыкновенное положение дел, и модель должна сказать об
     * этом словами, а не «внутренняя ошибка».
     */
    public function test_an_unavailable_analytics_server_is_explained(): void
    {
        $this->app->instance(ClickHouse::class, FakeClickHouse::down());

        Bismar::actingAs($this->userWith(Permission::ViewAnalytics))
            ->tool(SalesReportTool::class, [])
            ->assertHasErrors(['Сервер аналитики не ответил: Сервер аналитики недоступен.']);
    }

    /* ---------- Планы ---------- */

    public function test_a_plan_is_set_and_removed(): void
    {
        $department = Department::factory()->create();
        $keeper = $this->userWith(Permission::ManageSalesPlans);

        Bismar::actingAs($keeper)
            ->tool(SetSalesPlan::class, [
                'month' => '2026-10',
                'kind' => 'department',
                'department_id' => $department->getKey(),
                'revenue' => 1500000,
            ])
            ->assertOk()
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sales_plans', [
            'period' => '2026-10-01',
            'kind' => 'department',
            'department_id' => $department->getKey(),
        ]);

        // Второй раз тем же месяцем переписывает, а не заводит второй: план на
        // месяц у цели один.
        Bismar::actingAs($keeper)
            ->tool(SetSalesPlan::class, [
                'month' => '2026-10',
                'kind' => 'department',
                'department_id' => $department->getKey(),
                'revenue' => 1700000,
            ])
            ->assertOk();

        $this->assertSame(1, SalesPlan::query()->count());
        $this->assertSame(1700000.0, (float) SalesPlan::query()->sole()->revenue);

        Bismar::actingAs($keeper)
            ->tool(SetSalesPlan::class, [
                'month' => '2026-10',
                'kind' => 'department',
                'department_id' => $department->getKey(),
                'remove' => true,
            ])
            ->assertOk();

        $this->assertSame(0, SalesPlan::query()->count());
    }

    /** Ноль — это требование, а не отсутствие плана. */
    public function test_zero_is_a_plan(): void
    {
        $department = Department::factory()->create();

        Bismar::actingAs($this->userWith(Permission::ManageSalesPlans))
            ->tool(SetSalesPlan::class, [
                'month' => '2026-10',
                'kind' => 'department',
                'department_id' => $department->getKey(),
                'revenue' => 0,
            ])
            ->assertOk()
            ->assertHasNoErrors();

        $this->assertSame(0.0, (float) SalesPlan::query()->sole()->revenue);
    }

    public function test_a_personal_plan_needs_a_seller_not_a_department(): void
    {
        $department = Department::factory()->create();

        Bismar::actingAs($this->userWith(Permission::ManageSalesPlans))
            ->tool(SetSalesPlan::class, [
                'month' => '2026-10',
                'kind' => 'person',
                'department_id' => $department->getKey(),
                'revenue' => 100000,
            ])
            ->assertHasErrors(['Личный план ставится на продавца — укажите sales_person_id из sales_sellers.']);
    }

    public function test_the_plans_of_a_month_are_listed(): void
    {
        $department = Department::factory()->create();

        SalesPlan::query()->create([
            'period' => '2026-10-01',
            'kind' => 'department',
            'department_id' => $department->getKey(),
            'revenue' => 500000,
        ]);

        Bismar::actingAs($this->userWith(Permission::ManageSalesPlans))
            ->tool(SalesPlans::class, ['month' => '2026-10'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('month', '2026-10')
                // Целое, а не 500000.0: структурированный ответ едет JSON, и
                // дробная часть, равная нулю, в нём не сохраняется.
                ->where('plans.0.revenue', 500000)
                ->where('plans.0.kind', 'department')
                ->etc());
    }

    /* ---------- Сопоставление продавцов ---------- */

    public function test_a_seller_is_mapped_and_unmapped(): void
    {
        $department = Department::factory()->create();
        $person = User::factory()->create();
        $keeper = $this->userWith(Permission::ManageSalesPlans);

        Bismar::actingAs($keeper)
            ->tool(MapSeller::class, [
                'external_name' => 'ИП Курабанов (Основной)',
                'department_id' => $department->getKey(),
                'user_id' => $person->getKey(),
            ])
            ->assertOk()
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sales_people', [
            'external_name' => 'ИП Курабанов (Основной)',
            'department_id' => $department->getKey(),
            'user_id' => $person->getKey(),
        ]);

        // Снятая целиком связь удаляется, а не остаётся пустой строкой.
        Bismar::actingAs($keeper)
            ->tool(MapSeller::class, ['external_name' => 'ИП Курабанов (Основной)'])
            ->assertOk();

        $this->assertSame(0, SalesPerson::query()->count());
    }

    public function test_mapping_to_a_department_that_does_not_exist_is_refused(): void
    {
        Bismar::actingAs($this->userWith(Permission::ManageSalesPlans))
            ->tool(MapSeller::class, ['external_name' => 'Кто-то', 'department_id' => 9999])
            ->assertHasErrors(['Такого отдела в структуре нет.']);
    }
}
