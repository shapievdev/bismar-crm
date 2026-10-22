<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Actions\Sales\SuggestPlansFromHistory;
use App\Enums\Permission;
use App\Enums\SalesPlanKind;
use App\Models\Department;
use App\Models\SalesPerson;
use App\Models\SalesPlan;
use App\Support\Analytics\ClickHouse;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\CannedClickHouse;
use Tests\TestCase;

/**
 * Планы по умолчанию из прошлых месяцев.
 *
 * Первый план ставить не из чего, а без него отчёт остаётся без мерки.
 * Проверяется то, что здесь легко сделать неверно и трудно заметить: на что
 * делится норматив и что происходит с уже поставленным.
 */
final class DefaultPlansTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Три месяца истории: по 300 000 у одного и ничего у второго. */
    private function withHistory(): Department
    {
        $this->app->instance(ClickHouse::class, CannedClickHouse::withSellers([
            'Торгующий' => 900_000.0,
        ]));

        $department = Department::factory()->create(['parent_id' => 1]);

        SalesPerson::query()->create(['external_name' => 'Торгующий', 'department_id' => $department->id]);
        SalesPerson::query()->create(['external_name' => 'Ушедший', 'department_id' => $department->id]);

        return $department;
    }

    private function place(): array
    {
        return app(SuggestPlansFromHistory::class)(CarbonImmutable::parse('2026-10-01'));
    }

    /**
     * План отдела — его выручка за месяц, а не за все три.
     */
    public function test_a_department_gets_its_monthly_average(): void
    {
        $department = $this->withHistory();

        $this->place();

        $plan = SalesPlan::query()
            ->where('kind', SalesPlanKind::Department)
            ->where('department_id', $department->id)
            ->sole();

        $this->assertSame('300000.00', $plan->revenue);
    }

    /**
     * Норматив делится на торговавших, а не на всех сопоставленных.
     *
     * Сопоставление заводится по годовому списку имён, и в нём остаются
     * ушедшие. Поделив на них, получили бы норматив вдвое ниже правды — и
     * каждого работающего с двухсотпроцентным выполнением.
     */
    public function test_the_norm_counts_only_those_who_sold(): void
    {
        $department = $this->withHistory();

        $this->place();

        $norm = SalesPlan::query()
            ->where('kind', SalesPlanKind::Member)
            ->where('department_id', $department->id)
            ->sole();

        $this->assertSame('300000.00', $norm->revenue);
    }

    /**
     * Личный план ставится тому, у кого есть история, и только ему: для
     * остальных существует норматив отдела.
     */
    public function test_a_personal_plan_goes_to_those_with_a_history(): void
    {
        $this->withHistory();

        $placed = $this->place();

        $this->assertSame(1, $placed['people']);

        $person = SalesPerson::query()->where('external_name', 'Торгующий')->sole();

        $this->assertTrue(
            SalesPlan::query()
                ->where('kind', SalesPlanKind::Person)
                ->where('sales_person_id', $person->id)
                ->exists(),
        );
    }

    /**
     * Поставленное руками не переписывается.
     *
     * Нажатие, стирающее чужие решения, нельзя отменить, а узнают о нём в
     * конце месяца — когда спросят по чужой мерке.
     */
    public function test_a_hand_made_plan_survives(): void
    {
        $department = $this->withHistory();

        SalesPlan::query()->create([
            'period' => CarbonImmutable::parse('2026-10-01'),
            'kind' => SalesPlanKind::Department,
            'department_id' => $department->id,
            'revenue' => 777_000,
        ]);

        $placed = $this->place();

        $this->assertSame(0, $placed['departments']);
        $this->assertSame(
            '777000.00',
            SalesPlan::query()
                ->where('kind', SalesPlanKind::Department)
                ->where('department_id', $department->id)
                ->sole()
                ->revenue,
        );
    }

    /** Расставлять планы вправе тот же, кто их ставит руками. */
    public function test_the_button_needs_the_planning_right(): void
    {
        $this->app->instance(ClickHouse::class, CannedClickHouse::withSellers([]));

        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->postJson(route('sales.plans.suggest', ['period' => '2026-10']))
            ->assertForbidden();

        $this->actingAs($this->userWith(Permission::ManageSalesPlans))
            ->postJson(route('sales.plans.suggest', ['period' => '2026-10']))
            ->assertOk();
    }
}
