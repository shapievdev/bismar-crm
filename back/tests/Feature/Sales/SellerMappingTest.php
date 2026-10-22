<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\Permission;
use App\Models\Department;
use App\Models\SalesPerson;
use App\Support\Analytics\ClickHouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeClickHouse;
use Tests\TestCase;

/**
 * Сопоставление продавцов витрины с отделами.
 *
 * Связь ответственная: по ней выручка расходится по отделам и по ней же потом
 * спрашивают за план. Поэтому проверяется не только то, что она пишется, но и
 * то, кому это позволено и что происходит, когда её снимают.
 */
final class SellerMappingTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ClickHouse::class, FakeClickHouse::empty());
    }

    /**
     * Своё право, а не просмотр аналитики.
     *
     * Читать выручку и решать, чья она, — разные полномочия: первое открыто
     * всякому, кому показали дашборд, второе задаёт, по каким цифрам потом
     * спросят с отдела.
     */
    public function test_reading_the_dashboard_does_not_let_one_rewire_it(): void
    {
        $this->actingAs($this->userWith(Permission::ViewAnalytics))
            ->getJson(route('sales.sellers.index'))
            ->assertForbidden();

        $this->actingAs($this->userWith(Permission::ManageSalesPlans))
            ->getJson(route('sales.sellers.index'))
            ->assertOk();
    }

    public function test_a_guest_is_turned_away(): void
    {
        $this->getJson(route('sales.sellers.index'))->assertUnauthorized();
    }

    /** Связь заводится и переписывается одним и тем же обращением. */
    public function test_a_name_is_bound_and_rebound(): void
    {
        $person = $this->userWith();
        $first = Department::factory()->create(['parent_id' => 1]);
        $second = Department::factory()->create(['parent_id' => 1]);

        $this->actingAs($this->userWith(Permission::ManageSalesPlans))
            ->putJson(route('sales.sellers.update'), [
                'external_name' => 'ЛКМ Агабекова Света',
                'department_id' => $first->id,
                'user_id' => $person->id,
            ])
            ->assertOk();

        $this->actingAs($this->userWith(Permission::ManageSalesPlans))
            ->putJson(route('sales.sellers.update'), [
                'external_name' => 'ЛКМ Агабекова Света',
                'department_id' => $second->id,
                'user_id' => $person->id,
            ])
            ->assertOk();

        $this->assertSame(1, SalesPerson::query()->count());
        $this->assertSame($second->id, SalesPerson::query()->sole()->department_id);
    }

    /**
     * Снятая связь удаляется, а не остаётся пустой строкой.
     *
     * Строка без отдела и без человека ничего не утверждает, но попадалась бы
     * в списках и выглядела бы сопоставлением.
     */
    public function test_clearing_both_fields_removes_the_row(): void
    {
        $department = Department::factory()->create(['parent_id' => 1]);

        SalesPerson::query()->create([
            'external_name' => 'Касса 2',
            'department_id' => $department->id,
        ]);

        $this->actingAs($this->userWith(Permission::ManageSalesPlans))
            ->putJson(route('sales.sellers.update'), [
                'external_name' => 'Касса 2',
                'department_id' => null,
                'user_id' => null,
            ])
            ->assertOk();

        $this->assertSame(0, SalesPerson::query()->count());
    }

    /**
     * За кассой стоит смена, а не человек, — и это законная строка.
     */
    public function test_a_till_is_bound_to_a_department_without_a_person(): void
    {
        $department = Department::factory()->create(['parent_id' => 1]);

        $this->actingAs($this->userWith(Permission::ManageSalesPlans))
            ->putJson(route('sales.sellers.update'), [
                'external_name' => 'Касса 2',
                'department_id' => $department->id,
                'user_id' => null,
            ])
            ->assertOk();

        $this->assertNull(SalesPerson::query()->sole()->user_id);
    }

    /** Несуществующий отдел — отказ, а не связь в никуда. */
    public function test_an_unknown_department_is_refused(): void
    {
        $this->actingAs($this->userWith(Permission::ManageSalesPlans))
            ->putJson(route('sales.sellers.update'), [
                'external_name' => 'Касса 2',
                'department_id' => 9999,
                'user_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('department_id');
    }

    /**
     * Принятие подсказок не переписывает сделанное руками.
     *
     * Иначе одно нажатие отменяло бы всю прошлую работу, и хуже того — молча.
     */
    public function test_accepting_suggestions_leaves_manual_work_alone(): void
    {
        $kept = Department::factory()->create(['parent_id' => 1, 'name' => 'Крепеж']);
        Department::factory()->create(['parent_id' => 1, 'name' => 'Водоснабжение']);

        SalesPerson::query()->create([
            'external_name' => 'Вода Магомедов Хабиб',
            'department_id' => $kept->id,
        ]);

        $this->actingAs($this->userWith(Permission::ManageSalesPlans))
            ->postJson(route('sales.sellers.accept'))
            ->assertOk();

        $this->assertSame($kept->id, SalesPerson::query()->sole()->department_id);
    }
}
