<?php

declare(strict_types=1);

namespace App\Support\Sales;

use App\Enums\SalesPlanKind;
use App\Models\Department;
use App\Models\SalesPerson;
use App\Models\SalesPlan;
use App\Support\Analytics\EmployeeReport;
use App\Support\Analytics\Period;
use App\Support\Analytics\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * План и факт по отделам за месяц.
 *
 * Факт приходит из витрины по именам продавцов, план лежит в своей базе, а
 * связывает их сопоставление. Соединить это запросом нельзя — таблицы стоят на
 * разных серверах, — поэтому витрина отдаёт выручку по продавцам, а свёртка в
 * отделы считается здесь.
 *
 * Отделы показываются деревом, а не списком. Продавцов сопоставляют с нижними
 * отделами — ЛКМ, Электрика, Крепёж, — а спрашивают обычно с верхних:
 * «сколько дал гипермаркет». Поэтому у каждого отдела две выручки: своя, с его
 * собственных продавцов, и полная, со всеми, кто под ним. План ставится на
 * любой уровень и сравнивается с полной.
 *
 * Темп — отдельная величина и главная в текущем месяце. Сравнивать факт
 * шестнадцатого числа с планом на все тридцать один — значит каждый месяц до
 * последней недели показывать отставание, которого нет. Темп — это доля плана,
 * которая к сегодняшнему дню должна быть сделана, если идти ровно.
 */
final readonly class DepartmentReport
{
    public function __construct(private EmployeeReport $report) {}

    /**
     * @return array<string, mixed>
     */
    public function forMonth(CarbonImmutable $month): array
    {
        $month = $month->startOfMonth();
        $today = CarbonImmutable::today();

        // Месяц считается по сегодняшний день, а не по тридцать первое:
        // будущих продаж не бывает, и хвост из нулей только испортил бы график.
        $to = $month->endOfMonth()->greaterThan($today) ? $today : $month->endOfMonth();
        $isCurrent = $month->isSameMonth($today);

        $daysInMonth = $month->daysInMonth;
        $elapsed = $isCurrent ? min($daysInMonth, (int) $month->diffInDays($to) + 1) : $daysInMonth;

        $fact = $this->fact($month, $to);
        $people = SalesPerson::query()->with('user')->get();
        $plans = SalesPlan::query()->forMonth($month)->get();

        $departments = Department::query()->orderBy('parent_id')->orderBy('position')->get();

        $sellers = $this->sellers($fact, $people, $plans, $elapsed, $daysInMonth);
        $tree = $this->tree($departments, $sellers, $plans, $elapsed, $daysInMonth);

        $unassigned = array_values(array_filter(
            $sellers,
            static fn (array $seller): bool => $seller['department_id'] === null,
        ));

        return [
            'period' => [
                'month' => $month->format('Y-m'),
                'from' => $month->toDateString(),
                'to' => $to->toDateString(),
                'days_in_month' => $daysInMonth,
                'days_elapsed' => $elapsed,
                'is_current' => $isCurrent,
            ],
            'totals' => $this->totals($sellers, $tree, $elapsed, $daysInMonth),
            'departments' => $tree,
            'unassigned' => $unassigned,
        ];
    }

    /**
     * Выручка по именам продавцов за отрезок месяца.
     *
     * @return array<string, array{revenue: float, orders: int}>
     */
    private function fact(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->report->everyone(new ReportFilters(Period::between($from, $to)));

        $byName = [];

        foreach ($rows as $row) {
            $byName[(string) $row['name']] = [
                'revenue' => (float) $row['revenue'],
                'orders' => (int) $row['orders'],
            ];
        }

        return $byName;
    }

    /**
     * Продавцы: сопоставленные со своим планом и несопоставленные без него.
     *
     * Сопоставленный без продаж остаётся в списке. Пустая строка у человека,
     * с которого спрашивают план, — это и есть ответ; выброшенная, она
     * выглядела бы как «такого продавца нет».
     *
     * @param  array<string, array{revenue: float, orders: int}>  $fact
     * @param  Collection<int, SalesPerson>  $people
     * @param  Collection<int, SalesPlan>  $plans
     * @return list<array<string, mixed>>
     */
    private function sellers(array $fact, Collection $people, Collection $plans, int $elapsed, int $daysInMonth): array
    {
        $personal = $plans
            ->where('kind', SalesPlanKind::Person)
            ->keyBy('sales_person_id');

        $norms = $plans
            ->where('kind', SalesPlanKind::Member)
            ->keyBy('department_id');

        $sellers = [];

        foreach ($people as $person) {
            $row = $fact[$person->external_name] ?? ['revenue' => 0.0, 'orders' => 0];
            unset($fact[$person->external_name]);

            // Личный план сильнее норматива: норматив — это «сколько ждём с
            // любого», а личный — «сколько ждём с этого».
            $own = $personal->get($person->id);
            $norm = $person->department_id !== null ? $norms->get($person->department_id) : null;
            $plan = $own ?? $norm;

            $sellers[] = [
                'external_name' => $person->external_name,
                'sales_person_id' => $person->id,
                'department_id' => $person->department_id,
                'user_id' => $person->user_id,
                'user_name' => $person->user !== null
                    ? trim($person->user->last_name.' '.$person->user->first_name)
                    : null,
                ...$this->against($row['revenue'], $plan !== null ? (float) $plan->revenue : null, $elapsed, $daysInMonth),
                'orders' => $row['orders'],
                'plan_source' => $own !== null ? 'person' : ($norm !== null ? 'member' : null),
            ];
        }

        // Осталось то, чему сопоставления нет вовсе.
        foreach ($fact as $name => $row) {
            $sellers[] = [
                'external_name' => (string) $name,
                'sales_person_id' => null,
                'department_id' => null,
                'user_id' => null,
                'user_name' => null,
                ...$this->against($row['revenue'], null, $elapsed, $daysInMonth),
                'orders' => $row['orders'],
                'plan_source' => null,
            ];
        }

        usort($sellers, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return $sellers;
    }

    /**
     * Отделы деревом: только те, где что-то происходит, и их родители.
     *
     * Родители нужны целиком, даже пустые: ветка без середины — это не дерево,
     * а список с отступами, по которому не понять, кто кому подчинён.
     *
     * @param  Collection<int, Department>  $departments
     * @param  list<array<string, mixed>>  $sellers
     * @param  Collection<int, SalesPlan>  $plans
     * @return list<array<string, mixed>>
     */
    private function tree(Collection $departments, array $sellers, Collection $plans, int $elapsed, int $daysInMonth): array
    {
        $departmentPlans = $plans->where('kind', SalesPlanKind::Department)->keyBy('department_id');
        $norms = $plans->where('kind', SalesPlanKind::Member)->keyBy('department_id');

        /** @var array<int, list<array<string, mixed>>> $byDepartment */
        $byDepartment = [];
        /** @var array<int, array{revenue: float, orders: int, plan: float}> $own */
        $own = [];

        foreach ($sellers as $seller) {
            $id = $seller['department_id'];

            if ($id === null) {
                continue;
            }

            $byDepartment[$id][] = $seller;
            $own[$id] ??= ['revenue' => 0.0, 'orders' => 0, 'plan' => 0.0];
            $own[$id]['revenue'] += $seller['revenue'];
            $own[$id]['orders'] += $seller['orders'];
            $own[$id]['plan'] += $seller['plan'] ?? 0.0;
        }

        $children = $departments->groupBy('parent_id');

        // Полная выручка считается снизу вверх: лист знает только свою, узел —
        // свою плюс всё, что под ним.
        $rolled = [];
        $sellersUnder = [];

        $collect = function (?int $parentId) use (&$collect, $children, $own, $byDepartment, &$rolled, &$sellersUnder): array {
            $revenue = 0.0;
            $orders = 0;
            $count = 0;

            foreach ($children[$parentId] ?? [] as $department) {
                [$childRevenue, $childOrders, $childCount] = $collect($department->id);

                $revenue += $childRevenue;
                $orders += $childOrders;
                $count += $childCount;
            }

            if ($parentId !== null) {
                $revenue += $own[$parentId]['revenue'] ?? 0.0;
                $orders += $own[$parentId]['orders'] ?? 0;
                $count += count($byDepartment[$parentId] ?? []);

                $rolled[$parentId] = ['revenue' => $revenue, 'orders' => $orders];
                $sellersUnder[$parentId] = $count;
            }

            return [$revenue, $orders, $count];
        };

        $collect(null);

        $rows = [];

        $walk = function (?int $parentId, int $depth) use (
            &$walk, $children, $rolled, $own, $byDepartment, $sellersUnder,
            $departmentPlans, $norms, $elapsed, $daysInMonth, &$rows
        ): void {
            foreach ($children[$parentId] ?? [] as $department) {
                $id = $department->id;

                // Корень — это компания целиком, и строкой отдела он не
                // является: его цифра уже стоит в итоге, а в дереве он был бы
                // уровнем отступа, не несущим ничего.
                if ($department->parent_id === null) {
                    $walk($id, $depth);

                    continue;
                }

                $plan = $departmentPlans->get($id);
                $interesting = ($sellersUnder[$id] ?? 0) > 0 || $plan !== null || $norms->has($id);

                if (! $interesting) {
                    continue;
                }

                $rows[] = [
                    'id' => $id,
                    'name' => $department->name,
                    'depth' => $depth,
                    'parent_id' => $department->parent_id,
                    ...$this->against(
                        $rolled[$id]['revenue'] ?? 0.0,
                        $plan !== null ? (float) $plan->revenue : null,
                        $elapsed,
                        $daysInMonth,
                    ),
                    'orders' => $rolled[$id]['orders'] ?? 0,
                    'own_revenue' => round($own[$id]['revenue'] ?? 0.0, 2),
                    // Сколько роздано людям: план отдела не обязан совпадать с
                    // этой суммой, и расхождение — то, ради чего её показывают.
                    'sellers_plan' => round($own[$id]['plan'] ?? 0.0, 2),
                    'member_plan' => $norms->has($id) ? (float) $norms->get($id)->revenue : null,
                    'sellers' => $byDepartment[$id] ?? [],
                ];

                $walk($id, $depth + 1);
            }
        };

        $walk(null, 0);

        return $rows;
    }

    /**
     * Итог по компании.
     *
     * Выручка — вся, включая нераспределённую: она настоящая, и прятать её из
     * итога значило бы показывать компанию меньше, чем она есть.
     *
     * А вот выполнение считается не от неё. План обычно стоит не везде, и
     * делить всю выручку на сумму двух поставленных планов — получить двести
     * процентов там, где ничего не выполнено. Поэтому выполнение сравнивает
     * план с выручкой тех же отделов, которым он поставлен, и ни с чьей
     * больше.
     *
     * Складываются при этом только верхние из запланированных: если план стоит
     * и на гипермаркете, и на его ЛКМ, второй уже сидит внутри первого, и
     * сложенные они посчитали бы ЛКМ дважды.
     *
     * @param  list<array<string, mixed>>  $sellers
     * @param  list<array<string, mixed>>  $tree
     * @return array<string, float|int|null>
     */
    private function totals(array $sellers, array $tree, int $elapsed, int $daysInMonth): array
    {
        $revenue = 0.0;
        $unassigned = 0.0;

        foreach ($sellers as $seller) {
            $revenue += $seller['revenue'];

            if ($seller['department_id'] === null) {
                $unassigned += $seller['revenue'];
            }
        }

        $parents = [];

        foreach ($tree as $row) {
            $parents[$row['id']] = $row['parent_id'];
        }

        $planned = [];
        $plan = null;
        $planRevenue = 0.0;

        // Дерево приходит сверху вниз, поэтому к моменту разбора потомка его
        // предки уже помечены.
        foreach ($tree as $row) {
            if ($row['plan'] === null) {
                continue;
            }

            $planned[$row['id']] = true;

            // Ветка с планом выше уже посчитана целиком вместе с этой.
            if ($this->hasPlannedAncestor($row['parent_id'], $parents, $planned)) {
                continue;
            }

            $plan = ($plan ?? 0.0) + $row['plan'];
            $planRevenue += $row['revenue'];
        }

        return [
            ...$this->against($planRevenue, $plan, $elapsed, $daysInMonth),
            // Выручка компании целиком — отдельной величиной от той, что
            // сравнивается с планом: это разные числа, и выдать одно за другое
            // значило бы соврать в обе стороны сразу.
            'revenue' => round($revenue, 2),
            'planned_revenue' => round($planRevenue, 2),
            'unassigned_revenue' => round($unassigned, 2),
            'unassigned_share' => $revenue > 0 ? round($unassigned / $revenue * 100, 1) : 0.0,
            'sellers' => count($sellers),
        ];
    }

    /**
     * Есть ли выше по ветке отдел, которому план уже поставлен.
     *
     * @param  array<int, int|null>  $parents
     * @param  array<int, true>  $planned
     */
    private function hasPlannedAncestor(?int $parent, array $parents, array $planned): bool
    {
        while ($parent !== null) {
            if (isset($planned[$parent])) {
                return true;
            }

            $parent = $parents[$parent] ?? null;
        }

        return false;
    }

    /**
     * Факт против плана: выполнение от месяца целиком и темп на сегодня.
     *
     * @return array{revenue: float, plan: float|null, completion: float|null, pace: float|null, pace_completion: float|null}
     */
    private function against(float $revenue, ?float $plan, int $elapsed, int $daysInMonth): array
    {
        $pace = $plan !== null && $daysInMonth > 0
            ? round($plan * $elapsed / $daysInMonth, 2)
            : null;

        return [
            'revenue' => round($revenue, 2),
            'plan' => $plan,
            'completion' => $plan !== null && $plan > 0 ? round($revenue / $plan * 100, 1) : null,
            'pace' => $pace,
            'pace_completion' => $pace !== null && $pace > 0 ? round($revenue / $pace * 100, 1) : null,
        ];
    }
}
