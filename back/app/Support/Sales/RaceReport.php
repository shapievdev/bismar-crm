<?php

declare(strict_types=1);

namespace App\Support\Sales;

use App\Enums\DepartmentRole;
use App\Enums\SalesPlanKind;
use App\Models\Department;
use App\Models\SalesPerson;
use App\Models\SalesPlan;
use App\Support\Analytics\ClickHouse;
use App\Support\Analytics\EmployeeReport;
use App\Support\Analytics\Period;
use App\Support\Analytics\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Битва отделов: кто впереди по плану месяца и чем запомнился день.
 *
 * Тот же план и тот же факт, что на вкладке «Отделы», но повёрнутые к залу, а
 * не к директору: там таблица, по которой разбираются, здесь — табло, на
 * которое смотрят. Поэтому и величины другие — не рубли, а проценты плана и
 * места в гонке: рубли сравнивают отделы по размеру, а не по работе, и крепёж
 * против гипермаркета проиграет всегда.
 *
 * **Реального времени здесь нет и быть не может.** Витрина не хранит времени
 * продажи — только дату, — и приезжает ночной выгрузкой: сегодняшний день в
 * ней пуст, вчерашний неполон. Табло это не прячет: день показывается
 * последний полный, а рядом стоит дата, по которую дошла выгрузка. Показывать
 * «16:45, идём с опережением» на вчерашних данных значило бы сочинить цифру,
 * ради которой табло и повесили.
 *
 * План дня выводится из месячного, а не ставится отдельно. Выводится не
 * делением на число дней: суббота торгует не как понедельник, и ровная доля
 * сделала бы выходные вечными отстающими. Месячный план раскладывается по дням
 * в тех же пропорциях, в каких за последний квартал расходилась выручка по
 * дням недели.
 */
final readonly class RaceReport
{
    /** Насколько надо обогнать темп, чтобы считаться идущим впереди. */
    private const AHEAD = 103.0;

    private const BEHIND = 97.0;

    /** За сколько дней назад ищется последний полный день выгрузки. */
    private const LOOKBACK = 21;

    public function __construct(
        private ClickHouse $clickhouse,
        private EmployeeReport $report,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forMonth(CarbonImmutable $month): array
    {
        $month = $month->startOfMonth();
        $today = CarbonImmutable::today();

        $to = $month->endOfMonth()->greaterThan($today) ? $today : $month->endOfMonth();
        $isCurrent = $month->isSameMonth($today);

        $daysInMonth = $month->daysInMonth;
        $elapsed = $isCurrent ? min($daysInMonth, (int) $month->diffInDays($to) + 1) : $daysInMonth;

        $days = $this->days();
        $day = $this->lastFullDay($days, $to);
        $previous = $day?->subWeek();

        $people = SalesPerson::query()->with(['user', 'department'])->get()->keyBy('external_name');
        $plans = SalesPlan::query()->forMonth($month)->get();
        $departments = Department::query()->orderBy('parent_id')->orderBy('position')->get();

        $monthFact = $this->fact($month, $to);
        $dayFact = $day !== null ? $this->fact($day, $day) : [];
        $previousFact = $previous !== null ? $this->fact($previous, $previous) : [];

        $weights = $this->weekdayWeights();
        $dayShare = $day !== null ? $this->dayShare($month, $day, $weights) : 0.0;
        $elapsedShare = $this->elapsedShare($month, $to, $weights, $isCurrent);

        $race = $this->race(
            $departments, $people, $plans,
            $monthFact, $dayFact, $previousFact,
            $elapsedShare, $dayShare,
        );

        return [
            'period' => [
                'month' => $month->format('Y-m'),
                'days_in_month' => $daysInMonth,
                'days_elapsed' => $elapsed,
                'is_current' => $isCurrent,
            ],
            'day' => [
                'date' => $day?->toDateString(),
                'previous_date' => $previous?->toDateString(),
                // По какое число дошла выгрузка — и почему день не сегодняшний.
                'loaded_to' => $days === [] ? null : array_key_last($days),
                'share_of_month' => round($dayShare * 100, 2),
            ],
            'store' => $this->store($race, $monthFact, $dayFact, $elapsedShare, $dayShare),
            'departments' => $race,
            'consultants' => $this->consultants($people, $plans, $monthFact, $elapsedShare),
            'records' => $this->records($people, $dayFact, $previousFact, $race),
        ];
    }

    /**
     * Выручка и чеки по дням за последние недели.
     *
     * Нужны дважды: по ним ищется последний полный день, и по ним же видно, по
     * какое число дошла выгрузка.
     *
     * @return array<string, array{revenue: float, orders: int}>
     */
    private function days(): array
    {
        $rows = $this->clickhouse->select(<<<SQL
            SELECT
                toString(s.data)        AS date,
                round(sum(s.summa), 2)  AS revenue,
                uniqExact(s.zakaz_uid)  AS orders
            FROM {sales} AS s
            WHERE s.data >= today() - :lookback
            GROUP BY date
            ORDER BY date
        SQL, ['lookback' => self::LOOKBACK]);

        $days = [];

        foreach ($rows as $row) {
            $days[(string) $row['date']] = [
                'revenue' => (float) $row['revenue'],
                'orders' => (int) $row['orders'],
            ];
        }

        return $days;
    }

    /**
     * Последний день, за который выгрузка пришла целиком.
     *
     * Полнота меряется числом чеков против обычного: выгрузка приходит ночью, и
     * у недовезённого дня их в разы меньше — восемнадцать там, где обычно
     * тысяча. Порог в половину медианы отделяет это от просто тихого дня.
     *
     * @param  array<string, array{revenue: float, orders: int}>  $days
     */
    private function lastFullDay(array $days, CarbonImmutable $notLaterThan): ?CarbonImmutable
    {
        if ($days === []) {
            return null;
        }

        $orders = array_values(array_map(static fn (array $day): int => $day['orders'], $days));
        sort($orders);

        $median = $orders[intdiv(count($orders), 2)];
        $floor = $median / 2;

        foreach (array_reverse(array_keys($days)) as $date) {
            if ($date > $notLaterThan->toDateString()) {
                continue;
            }

            if ($days[$date]['orders'] >= $floor) {
                return CarbonImmutable::parse($date);
            }
        }

        return null;
    }

    /**
     * Выручка по именам продавцов за отрезок.
     *
     * @return array<string, array<string, float|int>>
     */
    private function fact(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->report->everyone(new ReportFilters(Period::between($from, $to)));

        $byName = [];

        foreach ($rows as $row) {
            $byName[(string) $row['name']] = $row;
        }

        return $byName;
    }

    /**
     * Во сколько раз день недели торгует против среднего дня.
     *
     * @return array<int, float>
     */
    private function weekdayWeights(): array
    {
        $rows = $this->clickhouse->remember('race.weekdays', fn (): array => $this->clickhouse->select(<<<SQL
            SELECT
                toDayOfWeek(s.data)     AS weekday,
                round(sum(s.summa), 2)  AS revenue,
                uniqExact(s.data)       AS days
            FROM {sales} AS s
            WHERE s.data >= today() - 90
            GROUP BY weekday
        SQL));

        $average = [];

        foreach ($rows as $row) {
            $days = max(1, (int) $row['days']);
            $average[(int) $row['weekday']] = (float) $row['revenue'] / $days;
        }

        // Без истории все дни равны — это хуже, но честнее выдумки.
        return $average === [] ? array_fill(1, 7, 1.0) : $average;
    }

    /**
     * Какая доля месячного плана приходится на этот день.
     *
     * @param  array<int, float>  $weights
     */
    private function dayShare(CarbonImmutable $month, CarbonImmutable $day, array $weights): float
    {
        $total = $this->monthWeight($month, $weights);

        return $total > 0 ? ($weights[(int) $day->dayOfWeekIso] ?? 0.0) / $total : 0.0;
    }

    /**
     * Какая доля месячного плана приходится на прошедшую часть месяца.
     *
     * @param  array<int, float>  $weights
     */
    private function elapsedShare(CarbonImmutable $month, CarbonImmutable $to, array $weights, bool $isCurrent): float
    {
        if (! $isCurrent) {
            return 1.0;
        }

        $total = $this->monthWeight($month, $weights);
        $gone = 0.0;

        for ($day = $month; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $gone += $weights[(int) $day->dayOfWeekIso] ?? 0.0;
        }

        return $total > 0 ? $gone / $total : 0.0;
    }

    /**
     * @param  array<int, float>  $weights
     */
    private function monthWeight(CarbonImmutable $month, array $weights): float
    {
        $total = 0.0;
        $last = $month->endOfMonth();

        for ($day = $month; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $total += $weights[(int) $day->dayOfWeekIso] ?? 0.0;
        }

        return $total;
    }

    /**
     * Гонка: отделы с планом, по проценту его выполнения.
     *
     * Участвуют те, кому план поставлен, и только нижние из них: гипермаркет
     * против своей же «Электрики» — не гонка, а сложение с самим собой.
     *
     * @param  Collection<int, Department>  $departments
     * @param  Collection<string, SalesPerson>  $people
     * @param  Collection<int, SalesPlan>  $plans
     * @param  array<string, array<string, float|int>>  $monthFact
     * @param  array<string, array<string, float|int>>  $dayFact
     * @param  array<string, array<string, float|int>>  $previousFact
     * @return list<array<string, mixed>>
     */
    private function race(
        Collection $departments,
        Collection $people,
        Collection $plans,
        array $monthFact,
        array $dayFact,
        array $previousFact,
        float $elapsedShare,
        float $dayShare,
    ): array {
        $departmentPlans = $plans->where('kind', SalesPlanKind::Department)->keyBy('department_id');
        $parents = $departments->pluck('parent_id', 'id');

        // Отдел участвует сам, если ни один его потомок плана не имеет:
        // иначе он на табло дублировал бы своих же детей.
        $planned = $departmentPlans->keys()->all();
        $hasPlannedChild = [];

        foreach ($planned as $id) {
            $parent = $parents[$id] ?? null;

            while ($parent !== null) {
                $hasPlannedChild[$parent] = true;
                $parent = $parents[$parent] ?? null;
            }
        }

        $byDepartment = [];

        foreach ($people as $person) {
            if ($person->department_id === null) {
                continue;
            }

            $byDepartment[$person->department_id][] = $person;
        }

        $rows = [];

        foreach ($departments as $department) {
            $plan = $departmentPlans->get($department->id);

            if ($plan === null || isset($hasPlannedChild[$department->id])) {
                continue;
            }

            $month = $this->sum($byDepartment, $department->id, $departments, $monthFact);
            $day = $this->sum($byDepartment, $department->id, $departments, $dayFact);
            $before = $this->sum($byDepartment, $department->id, $departments, $previousFact);

            $planValue = (float) $plan->revenue;
            $pace = $planValue * $elapsedShare;
            $dayPlan = $planValue * $dayShare;

            $rows[] = [
                'id' => $department->id,
                'name' => $department->name,
                'head' => $this->head($department),
                'revenue_month' => round($month['revenue'], 2),
                'orders_month' => $month['orders'],
                'plan_month' => $planValue,
                'completion_month' => $planValue > 0 ? round($month['revenue'] / $planValue * 100, 1) : null,
                'pace_month' => round($pace, 2),
                'pace_completion' => $pace > 0 ? round($month['revenue'] / $pace * 100, 1) : null,
                'revenue_day' => round($day['revenue'], 2),
                'plan_day' => round($dayPlan, 2),
                'completion_day' => $dayPlan > 0 ? round($day['revenue'] / $dayPlan * 100, 1) : null,
                'orders_day' => $day['orders'],
                'lines_day' => $day['lines'],
                'previous_day' => $before,
                'change_day' => $before['revenue'] > 0
                    ? round(($day['revenue'] - $before['revenue']) / $before['revenue'] * 100, 1)
                    : null,
                'status' => $this->status($pace > 0 ? $month['revenue'] / $pace * 100 : null),
            ];
        }

        // Место в гонке — по темпу месяца, а не по сегодняшнему проценту: день
        // стоит три процента месяца, и первое место за один удачный вторник
        // обесценило бы и гонку, и план. Статус у строки о том же, иначе
        // первый в списке оказывался бы «догоняющим».
        usort($rows, static fn (array $a, array $b): int => ($b['pace_completion'] ?? -1) <=> ($a['pace_completion'] ?? -1));

        return $rows;
    }

    /**
     * Сумма по отделу вместе со всеми, кто под ним.
     *
     * @param  array<int, list<SalesPerson>>  $byDepartment
     * @param  Collection<int, Department>  $departments
     * @param  array<string, array<string, float|int>>  $fact
     * @return array{revenue: float, orders: int, lines: int}
     */
    private function sum(array $byDepartment, int $departmentId, Collection $departments, array $fact): array
    {
        $ids = [$departmentId];
        $queue = [$departmentId];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($departments->where('parent_id', $current) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        $revenue = 0.0;
        $orders = 0;
        $lines = 0;

        foreach ($ids as $id) {
            foreach ($byDepartment[$id] ?? [] as $person) {
                $row = $fact[$person->external_name] ?? null;

                if ($row === null) {
                    continue;
                }

                $revenue += (float) $row['revenue'];
                $orders += (int) $row['orders'];
                $lines += (int) $row['lines'];
            }
        }

        return ['revenue' => $revenue, 'orders' => $orders, 'lines' => $lines];
    }

    private function head(Department $department): ?string
    {
        $head = $department->peopleAs(DepartmentRole::Head)->first();

        return $head !== null ? trim($head->last_name.' '.mb_substr($head->first_name, 0, 1).'.') : null;
    }

    private function status(?float $paceCompletion): string
    {
        if ($paceCompletion === null) {
            return 'unknown';
        }

        return match (true) {
            $paceCompletion >= self::AHEAD => 'ahead',
            $paceCompletion <= self::BEHIND => 'behind',
            default => 'on-track',
        };
    }

    /**
     * Магазин целиком — ровно сумма участников гонки.
     *
     * Не вся выручка витрины: план покрывает те отделы, которым он поставлен, а
     * делить всю торговлю на их мерку — получить сто тридцать процентов там,
     * где идут вровень. Поэтому и факт, и план здесь об одних и тех же людях, а
     * остаток показан отдельной величиной, чтобы не пропасть молча.
     *
     * @param  list<array<string, mixed>>  $race
     * @param  array<string, array<string, float|int>>  $monthFact
     * @param  array<string, array<string, float|int>>  $dayFact
     * @return array<string, mixed>
     */
    private function store(array $race, array $monthFact, array $dayFact, float $elapsedShare, float $dayShare): array
    {
        $month = ['revenue' => 0.0, 'orders' => 0, 'lines' => 0];
        $day = ['revenue' => 0.0, 'orders' => 0, 'lines' => 0];
        $before = ['revenue' => 0.0, 'orders' => 0, 'lines' => 0];
        $plan = null;

        foreach ($race as $row) {
            $month['revenue'] += $row['revenue_month'];
            $month['orders'] += $row['orders_month'];

            $day['revenue'] += $row['revenue_day'];
            $day['orders'] += $row['orders_day'];
            $day['lines'] += $row['lines_day'];

            $before['revenue'] += $row['previous_day']['revenue'];
            $before['orders'] += $row['previous_day']['orders'];
            $before['lines'] += $row['previous_day']['lines'];

            $plan = ($plan ?? 0.0) + $row['plan_month'];
        }

        $everything = $this->totals($monthFact);
        $everythingDay = $this->totals($dayFact);

        $pace = $plan !== null ? $plan * $elapsedShare : null;
        $dayPlan = $plan !== null ? $plan * $dayShare : null;

        return [
            'revenue_month' => round($month['revenue'], 2),
            'plan_month' => $plan,
            'completion_month' => $plan !== null && $plan > 0 ? round($month['revenue'] / $plan * 100, 1) : null,
            'pace_month' => $pace !== null ? round($pace, 2) : null,
            'pace_completion' => $pace !== null && $pace > 0 ? round($month['revenue'] / $pace * 100, 1) : null,
            // Прогноз: если дальше пойдёт как шло, сколько выйдет к концу
            // месяца. Доля прошедшего берётся по тем же весам дней недели, что
            // и темп, — иначе месяц с четырьмя субботами впереди занижался бы.
            'forecast' => $elapsedShare > 0 ? round($month['revenue'] / $elapsedShare, 2) : null,
            'forecast_completion' => $plan !== null && $plan > 0 && $elapsedShare > 0
                ? round($month['revenue'] / $elapsedShare / $plan * 100, 1)
                : null,

            'revenue_day' => round($day['revenue'], 2),
            'plan_day' => $dayPlan !== null ? round($dayPlan, 2) : null,
            'completion_day' => $dayPlan !== null && $dayPlan > 0 ? round($day['revenue'] / $dayPlan * 100, 1) : null,
            'orders_day' => $day['orders'],
            'average_order_day' => $day['orders'] > 0 ? round($day['revenue'] / $day['orders'], 2) : 0.0,
            'lines_per_order_day' => $day['orders'] > 0 ? round($day['lines'] / $day['orders'], 2) : 0.0,

            // Торговля, которая в гонке не участвует: отделы без плана и
            // продавцы, которых ещё не развели по отделам.
            'outside_month' => round($everything['revenue'] - $month['revenue'], 2),
            'outside_day' => round($everythingDay['revenue'] - $day['revenue'], 2),

            // К прошлому такому же дню недели, а не ко вчера: вторник со
            // средой различаются сами по себе, и сравнение с ними мерило бы
            // день недели, а не работу.
            'against_previous' => [
                'orders' => $this->change((float) $day['orders'], (float) $before['orders']),
                'average_order' => $this->change(
                    $day['orders'] > 0 ? $day['revenue'] / $day['orders'] : 0.0,
                    $before['orders'] > 0 ? $before['revenue'] / $before['orders'] : 0.0,
                ),
                'lines_per_order' => $this->change(
                    $day['orders'] > 0 ? $day['lines'] / $day['orders'] : 0.0,
                    $before['orders'] > 0 ? $before['lines'] / $before['orders'] : 0.0,
                ),
                'revenue' => $this->change($day['revenue'], $before['revenue']),
            ],
        ];
    }

    /**
     * Лучшие консультанты месяца — по проценту личного плана.
     *
     * Процент, а не рубли: с кассы корпоративного отдела спрашивают миллионы, с
     * консультанта крепежа — сотни тысяч, и рейтинг в рублях был бы рейтингом
     * отделов, в котором одни и те же люди стоят первыми всегда.
     *
     * @param  Collection<string, SalesPerson>  $people
     * @param  Collection<int, SalesPlan>  $plans
     * @param  array<string, array<string, float|int>>  $fact
     * @return list<array<string, mixed>>
     */
    private function consultants(Collection $people, Collection $plans, array $fact, float $elapsedShare): array
    {
        $personal = $plans->where('kind', SalesPlanKind::Person)->keyBy('sales_person_id');
        $norms = $plans->where('kind', SalesPlanKind::Member)->keyBy('department_id');

        $rows = [];

        foreach ($people as $person) {
            $plan = $personal->get($person->id)
                ?? ($person->department_id !== null ? $norms->get($person->department_id) : null);

            if ($plan === null) {
                continue;
            }

            $planValue = (float) $plan->revenue;

            if ($planValue <= 0) {
                continue;
            }

            $revenue = (float) ($fact[$person->external_name]['revenue'] ?? 0);
            $pace = $planValue * $elapsedShare;

            $rows[] = [
                'name' => $person->user !== null
                    ? trim($person->user->last_name.' '.mb_substr($person->user->first_name, 0, 1).'.')
                    : $person->external_name,
                'external_name' => $person->external_name,
                'department' => $person->department?->name,
                'revenue' => round($revenue, 2),
                'plan' => $planValue,
                'completion' => round($revenue / $planValue * 100, 1),
                'pace_completion' => $pace > 0 ? round($revenue / $pace * 100, 1) : null,
                'plan_source' => $personal->has($person->id) ? 'person' : 'member',
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['completion'] <=> $a['completion']);

        return array_slice($rows, 0, 10);
    }

    /**
     * Рекорды дня: чем этот день запомнился.
     *
     * Каждый — про своё, и все четыре нарочно разные: доля в продажах, рост к
     * прошлой неделе, средний чек и позиции в чеке. Один показатель назвал бы
     * лучшим всегда одного и того же.
     *
     * @param  Collection<string, SalesPerson>  $people
     * @param  array<string, array<string, float|int>>  $dayFact
     * @param  array<string, array<string, float|int>>  $previousFact
     * @param  list<array<string, mixed>>  $race
     * @return array<string, mixed>
     */
    private function records(Collection $people, array $dayFact, array $previousFact, array $race): array
    {
        // Средним по магазину считается среднее тех же людей, между кем и
        // разыгрывается рекорд: оптовая накладная на миллион сделала бы
        // «средний чек магазина» недостижимым для любого консультанта.
        $day = $this->totals(array_filter(
            $dayFact,
            static fn (string $name): bool => $people->has($name),
            ARRAY_FILTER_USE_KEY,
        ));

        $named = static function (string $external) use ($people): array {
            $person = $people->get($external);

            return [
                'name' => $person?->user !== null
                    ? trim($person->user->last_name.' '.mb_substr($person->user->first_name, 0, 1).'.')
                    : $external,
                'department' => $person?->department?->name,
            ];
        };

        $leader = null;
        $bestOrder = null;
        $bestLines = null;

        $storeAverage = $day['orders'] > 0 ? $day['revenue'] / $day['orders'] : 0.0;
        $storeLines = $day['orders'] > 0 ? $day['lines'] / $day['orders'] : 0.0;

        foreach ($dayFact as $name => $row) {
            if (! $people->has((string) $name)) {
                continue;
            }

            $revenue = (float) $row['revenue'];
            $orders = (int) $row['orders'];

            if ($revenue > 0 && ($leader === null || $revenue > $leader['value'])) {
                $leader = ['value' => $revenue, 'external' => (string) $name];
            }

            // Рекорд по среднему чеку даётся не за одну крупную покупку:
            // с тремя чеками «лучший средний» — это один клиент, а не работа.
            if ($orders < 10) {
                continue;
            }

            $average = $revenue / $orders;
            $lines = (int) $row['lines'] / $orders;

            if ($storeAverage > 0 && ($bestOrder === null || $average > $bestOrder['value'])) {
                $bestOrder = ['value' => $average, 'external' => (string) $name];
            }

            if ($storeLines > 0 && ($bestLines === null || $lines > $bestLines['value'])) {
                $bestLines = ['value' => $lines, 'external' => (string) $name];
            }
        }

        $surge = null;

        foreach ($race as $row) {
            if ($row['change_day'] !== null && ($surge === null || $row['change_day'] > $surge['change'])) {
                $surge = ['name' => $row['name'], 'change' => $row['change_day']];
            }
        }

        return [
            'leader' => $leader === null ? null : [
                ...$named($leader['external']),
                'share' => $day['revenue'] > 0 ? round($leader['value'] / $day['revenue'] * 100, 1) : 0.0,
                'revenue' => round($leader['value'], 2),
            ],
            'surge' => $surge,
            'average_order' => $bestOrder === null ? null : [
                ...$named($bestOrder['external']),
                'value' => round($bestOrder['value'], 2),
                'change' => $this->change($bestOrder['value'], $storeAverage),
            ],
            'lines_per_order' => $bestLines === null ? null : [
                ...$named($bestLines['external']),
                'value' => round($bestLines['value'], 2),
                'change' => $this->change($bestLines['value'], $storeLines),
            ],
        ];
    }

    /**
     * @param  array<string, array<string, float|int>>  $fact
     * @return array{revenue: float, orders: int, lines: int}
     */
    private function totals(array $fact): array
    {
        $revenue = 0.0;
        $orders = 0;
        $lines = 0;

        foreach ($fact as $row) {
            $revenue += (float) $row['revenue'];
            $orders += (int) $row['orders'];
            $lines += (int) $row['lines'];
        }

        return ['revenue' => $revenue, 'orders' => $orders, 'lines' => $lines];
    }

    private function change(float $now, float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }
}
