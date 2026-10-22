<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Enums\SalesPlanKind;
use App\Models\Department;
use App\Models\SalesPerson;
use App\Models\SalesPlan;
use App\Support\Analytics\EmployeeReport;
use App\Support\Analytics\Period;
use App\Support\Analytics\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Планы по умолчанию — из того, как шло раньше.
 *
 * Ставить первый план на пустом месте не из чего, а не поставить его вовсе
 * значит оставить отчёт без мерки: проценты выполнения не покажутся ни у кого.
 * Поэтому план берётся с факта закрытых месяцев — это не прогноз и не цель, а
 * отправная точка, которую правят руками.
 *
 * Считается по трём последним **закрытым** месяцам. Текущий не в счёт: он
 * неполон, и среднее с ним занизило бы план ровно на недожитую часть месяца.
 * Три, а не один: один случайный месяц — это один отпуск или один крупный
 * тендер, а три сглаживают и то и другое, не успевая устареть.
 *
 * План отдела — его выручка за те же месяцы вместе со всеми, кто под ним.
 * Норматив — средняя выручка торговавшего продавца отдела.
 *
 * Поставленное руками не трогается никогда. Нажатие, стирающее чужие решения,
 * нельзя отменить, а узнают о нём в конце месяца — когда спросят по чужой
 * мерке.
 */
final readonly class SuggestPlansFromHistory
{
    /** Сколько закрытых месяцев берётся в расчёт. */
    private const MONTHS = 3;

    public function __construct(private EmployeeReport $report) {}

    /**
     * Сколько планов завелось: на отделы, нормативов и личных.
     *
     * @return array{departments: int, members: int, people: int, months: int}
     */
    public function __invoke(CarbonImmutable $month): array
    {
        $month = $month->startOfMonth();

        $history = $this->history($month);

        if ($history === []) {
            return ['departments' => 0, 'members' => 0, 'people' => 0, 'months' => 0];
        }

        $people = SalesPerson::query()->assigned()->get();
        $departments = Department::query()->get();

        // Выручка отдела — со всеми, кто под ним: план ставят и на гипермаркет,
        // и на его ЛКМ, и первый обязан включать второй.
        $own = [];
        $sellers = [];

        foreach ($people as $person) {
            $revenue = $history[$person->external_name] ?? 0.0;

            $own[$person->department_id] = ($own[$person->department_id] ?? 0.0) + $revenue;

            // Делителем норматива идут торговавшие, а не все сопоставленные.
            // Сопоставление заводится по годовому списку имён, и в нём остаются
            // ушедшие: поделив на них, получим норматив втрое ниже правды, а в
            // отчёте — каждого работающего с четырёхсотпроцентным выполнением.
            if ($revenue > 0) {
                $sellers[$person->department_id] = ($sellers[$person->department_id] ?? 0) + 1;
            }
        }

        $rolled = $this->roll($departments, $own);

        $existing = SalesPlan::query()->forMonth($month)->get();
        $hasDepartment = $existing->where('kind', SalesPlanKind::Department)->pluck('department_id')->all();
        $hasMember = $existing->where('kind', SalesPlanKind::Member)->pluck('department_id')->all();
        $hasPerson = $existing->where('kind', SalesPlanKind::Person)->pluck('sales_person_id')->all();

        $placed = ['departments' => 0, 'members' => 0, 'people' => 0, 'months' => self::MONTHS];

        DB::transaction(function () use ($month, $rolled, $own, $sellers, $people, $history, $hasDepartment, $hasMember, $hasPerson, &$placed): void {
            foreach ($rolled as $departmentId => $revenue) {
                if ($revenue <= 0 || in_array($departmentId, $hasDepartment, true)) {
                    continue;
                }

                SalesPlan::query()->create([
                    'period' => $month,
                    'kind' => SalesPlanKind::Department,
                    'department_id' => $departmentId,
                    'revenue' => $this->rounded($revenue),
                ]);

                $placed['departments']++;
            }

            foreach ($sellers as $departmentId => $count) {
                $revenue = $own[$departmentId] ?? 0.0;

                if ($revenue <= 0 || $count === 0 || in_array($departmentId, $hasMember, true)) {
                    continue;
                }

                // Норматив — средняя выручка продавца этого отдела, а не доля
                // плана отдела: план отдела может стоять выше розданного, и
                // делить его на людей значило бы требовать с каждого то, чего
                // с него никто не просил.
                SalesPlan::query()->create([
                    'period' => $month,
                    'kind' => SalesPlanKind::Member,
                    'department_id' => $departmentId,
                    'revenue' => $this->rounded($revenue / $count),
                ]);

                $placed['members']++;
            }

            /*
             * Личный план — своя же история продавца.
             *
             * Он нужен именно потому, что норматив — среднее: у звезды отдела
             * выручка втрое выше средней, и по нормативу она вечно выполняет
             * триста процентов, а отстающий — сорок. Ни то, ни другое не
             * говорит о работе. Со своей же историей сравнение становится
             * осмысленным: лучше или хуже, чем сам в прошлые месяцы.
             *
             * Тем, кто не торговал, личный план не ставится — для них и
             * существует норматив отдела.
             */
            foreach ($people as $person) {
                $revenue = $history[$person->external_name] ?? 0.0;

                if ($revenue <= 0 || in_array($person->id, $hasPerson, true)) {
                    continue;
                }

                SalesPlan::query()->create([
                    'period' => $month,
                    'kind' => SalesPlanKind::Person,
                    'sales_person_id' => $person->id,
                    'revenue' => $this->rounded($revenue),
                ]);

                $placed['people']++;
            }
        });

        return $placed;
    }

    /**
     * План — круглое число.
     *
     * «82 334 270,98» — это не план, а среднее арифметическое, выданное за
     * план: копейки в мерке на месяц не значат ничего, а читать её мешают.
     * Округление до тысяч оставляет от неё ровно то, о чём договариваются.
     */
    private function rounded(float $revenue): float
    {
        return $revenue >= 1000 ? round($revenue / 1000) * 1000 : round($revenue, 2);
    }

    /**
     * Средняя месячная выручка по именам продавцов за закрытые месяцы.
     *
     * @return array<string, float>
     */
    private function history(CarbonImmutable $month): array
    {
        $to = $month->subDay();
        $from = $month->subMonths(self::MONTHS)->startOfMonth();

        if ($to->lessThan($from)) {
            return [];
        }

        $rows = $this->report->everyone(new ReportFilters(Period::between($from, $to)));

        $average = [];

        foreach ($rows as $row) {
            $average[(string) $row['name']] = (float) $row['revenue'] / self::MONTHS;
        }

        return $average;
    }

    /**
     * Выручка каждого отдела вместе со всеми, кто под ним.
     *
     * @param  Collection<int, Department>  $departments
     * @param  array<int, float>  $own
     * @return array<int, float>
     */
    private function roll(Collection $departments, array $own): array
    {
        $children = $departments->groupBy('parent_id');
        $rolled = [];

        $collect = function (?int $parentId) use (&$collect, $children, $own, &$rolled): float {
            $revenue = 0.0;

            foreach ($children[$parentId] ?? [] as $department) {
                $revenue += $collect($department->id);
            }

            if ($parentId !== null) {
                $revenue += $own[$parentId] ?? 0.0;
                $rolled[$parentId] = $revenue;
            }

            return $revenue;
        };

        $collect(null);

        // Корень — это компания, а не отдел продаж: план на неё не ставится.
        $root = $departments->firstWhere('parent_id', null);

        if ($root !== null) {
            unset($rolled[$root->id]);
        }

        return $rolled;
    }
}
