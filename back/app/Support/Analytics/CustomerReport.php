<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * Клиенты: кто покупает, кто вернулся и кто перестал.
 *
 * RFM, когорта, LTV и номер заказа в жизни клиента витриной не размечены —
 * они считаются по всей её истории в `{customers}` и `{orders}`, а отчёт
 * присоединяет готовое к выбранному периоду. Порядок именно такой, и это
 * главное здесь: посчитанная на окне отчёта, разметка означала бы другое —
 * «новым» в месячном срезе оказался бы всякий, кто не покупал последний месяц,
 * а когорта превратилась бы в «впервые замечен в выбранном периоде».
 *
 * Из всех метрик вычтена анонимная розница — покупки без карты, которые
 * приходят без контрагента. В выручке эти деньги настоящие, но клиентом такая
 * строка не является: за ней десятки тысяч разных людей. Оставленная в
 * подсчёте, она даёт «одного клиента» с оборотом в сотни миллионов, первое
 * место в списке лучших и средний LTV, поделённый не на то число.
 */
final readonly class CustomerReport
{
    public function __construct(private ClickHouse $clickhouse) {}

    /**
     * Сколько клиентов, сколько из них пришли впервые и сколько вернулись.
     *
     * @return array{current: array<string, float|int>, previous: array<string, float|int>}
     */
    public function summary(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('customers.summary:'.$filters->signature(), fn (): array => [
            'current' => $this->totals($filters),
            'previous' => $this->totals($filters->withPeriod($filters->period->previous())),
        ]);
    }

    /**
     * @return array<string, float|int>
     */
    private function totals(ReportFilters $filters): array
    {
        [$where, $bindings] = $this->scope($filters);

        $bindings['pause'] = $this->pause();

        $row = $this->clickhouse->selectOne(<<<SQL
            SELECT
                uniqExact(s.klient_id)                                      AS customers,
                -- Первый заказ в жизни клиента — по всей истории выгрузки, а
                -- не по окну отчёта.
                uniqExactIf(s.klient_id, o.is_first = 1)                    AS new_customers,
                uniqExactIf(s.klient_id, o.days_since_prev > :pause)        AS revived_customers,
                round(sum(s.summa), 2)                                      AS revenue,
                round(sum(s.pribyl), 2)                                     AS profit,
                uniqExact(s.zakaz_uid)                                      AS orders
            FROM {sales} AS s
            LEFT JOIN {orders} AS o ON o.klient_id = s.klient_id AND o.zakaz_uid = s.zakaz_uid
            WHERE {$where}
        SQL, $bindings);

        $customers = (int) ($row['customers'] ?? 0);
        $revenue = (float) ($row['revenue'] ?? 0);
        $orders = (int) ($row['orders'] ?? 0);
        $newCustomers = (int) ($row['new_customers'] ?? 0);

        return [
            'customers' => $customers,
            'new_customers' => $newCustomers,
            'new_share' => $customers > 0 ? round($newCustomers / $customers * 100, 1) : 0.0,
            'revived_customers' => (int) ($row['revived_customers'] ?? 0),
            'returning_customers' => max(0, $customers - $newCustomers),
            'revenue' => $revenue,
            'profit' => (float) ($row['profit'] ?? 0),
            'orders' => $orders,
            // Средние по клиенту, а не по строке: строк у крупного клиента
            // сотни, и среднее по ним говорило бы о размере накладной.
            'revenue_per_customer' => $customers > 0 ? round($revenue / $customers, 2) : 0.0,
            'orders_per_customer' => $customers > 0 ? round($orders / $customers, 2) : 0.0,
        ];
    }

    /**
     * RFM: во что сложились давность покупки и ценность клиента.
     *
     * Сегменты пронумерованы («1. Лучшие», «6. Потерянные»), и сортировка по
     * имени выстраивает их от лучших к потерянным сама.
     *
     * @return list<array<string, float|int|string>>
     */
    public function segments(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('customers.rfm:'.$filters->signature(), function () use ($filters): array {
            [$where, $bindings] = $this->scope($filters);

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    c.rfm_segment               AS segment,
                    uniqExact(s.klient_id)      AS customers,
                    round(sum(s.summa), 2)      AS revenue,
                    round(sum(s.pribyl), 2)     AS profit,
                    uniqExact(s.zakaz_uid)      AS orders
                FROM {sales} AS s
                LEFT JOIN {customers} AS c ON c.klient_id = s.klient_id
                WHERE {$where}
                GROUP BY segment
                ORDER BY segment
            SQL, $bindings);

            return array_map(static function (array $row): array {
                $customers = (int) $row['customers'];
                $revenue = (float) $row['revenue'];

                return [
                    'segment' => ((string) $row['segment']) !== '' ? (string) $row['segment'] : '(не размечен)',
                    'customers' => $customers,
                    'revenue' => $revenue,
                    'profit' => (float) $row['profit'],
                    'orders' => (int) $row['orders'],
                    'revenue_per_customer' => $customers > 0 ? round($revenue / $customers, 2) : 0.0,
                ];
            }, $rows);
        });
    }

    /**
     * Откуда пришла выручка: новый клиент, повторный, вернувшийся, возврат.
     *
     * Тип — свойство заказа, а не клиента: один и тот же человек в этом месяце
     * вернулся после паузы, а в следующем купил повторно. Поэтому считается он
     * по предыдущему заказу того же клиента, найденному на всей истории.
     *
     * @return list<array<string, float|int|string>>
     */
    public function orderTypes(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('customers.types:'.$filters->signature(), function () use ($filters): array {
            [$where, $bindings] = $this->scope($filters);
            $bindings['pause'] = $this->pause();

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    multiIf(
                        s.vozvrat = 1,                  'Возврат',
                        o.is_first = 1,                 'Первый заказ',
                        o.days_since_prev > :pause,     'Вернулся после паузы',
                        'Повторный'
                    )                           AS type,
                    uniqExact(s.zakaz_uid)      AS orders,
                    uniqExact(s.klient_id)      AS customers,
                    round(sum(s.summa), 2)      AS revenue
                FROM {sales} AS s
                LEFT JOIN {orders} AS o ON o.klient_id = s.klient_id AND o.zakaz_uid = s.zakaz_uid
                WHERE {$where}
                GROUP BY type
                ORDER BY revenue DESC
            SQL, $bindings);

            return array_map(static fn (array $row): array => [
                'type' => ((string) $row['type']) !== '' ? (string) $row['type'] : '(не указан)',
                'orders' => (int) $row['orders'],
                'customers' => (int) $row['customers'],
                'revenue' => (float) $row['revenue'],
            ], $rows);
        });
    }

    /**
     * Когорты: как расходятся месяцы, в которые клиенты пришли впервые.
     *
     * Берутся только надёжные (`kogorta_nadezhna`) — те, чья первая покупка
     * случилась не в первый месяц выгрузки. У остальных первый заказ мог
     * произойти до её начала, и «новичком» там числится тот, кто покупает
     * десять лет.
     *
     * @return list<array<string, float|int|string>>
     */
    public function cohorts(ReportFilters $filters, int $limit): array
    {
        $limit = $this->boundedLimit($limit);

        return $this->clickhouse->remember("customers.cohorts:{$limit}:".$filters->signature(), function () use ($filters, $limit): array {
            [$where, $bindings] = $this->scope($filters);
            $bindings['limit'] = $limit;

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    c.kogorta                   AS cohort,
                    uniqExact(s.klient_id)      AS customers,
                    round(sum(s.summa), 2)      AS revenue,
                    uniqExact(s.zakaz_uid)      AS orders
                FROM {sales} AS s
                LEFT JOIN {customers} AS c ON c.klient_id = s.klient_id
                WHERE {$where}
                  AND c.kogorta_nadezhna = 1
                  AND c.kogorta != ''
                GROUP BY cohort
                ORDER BY cohort DESC
                LIMIT :limit
            SQL, $bindings);

            return array_map(static function (array $row): array {
                $customers = (int) $row['customers'];
                $revenue = (float) $row['revenue'];

                return [
                    'cohort' => (string) $row['cohort'],
                    'customers' => $customers,
                    'revenue' => $revenue,
                    'orders' => (int) $row['orders'],
                    'revenue_per_customer' => $customers > 0 ? round($revenue / $customers, 2) : 0.0,
                ];
            }, $rows);
        });
    }

    /**
     * Крупнейшие клиенты — с тем, что известно о них сверх периода.
     *
     * LTV и число заказов относятся к клиенту целиком, а не к выбранному
     * отрезку, поэтому берутся `max()` внутри группы, а не суммой: сложенные по
     * строкам, они выросли бы во столько раз, сколько у клиента позиций.
     *
     * @return list<array<string, float|int|string>>
     */
    public function top(ReportFilters $filters, int $limit): array
    {
        $limit = $this->boundedLimit($limit);

        return $this->clickhouse->remember("customers.top:{$limit}:".$filters->signature(), function () use ($filters, $limit): array {
            [$where, $bindings] = $this->scope($filters);
            $bindings['limit'] = $limit;

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    s.klient                    AS name,
                    any(s.segment_klienta)      AS segment,
                    any(c.rfm_segment)          AS rfm,
                    round(sum(s.summa), 2)      AS revenue,
                    round(sum(s.pribyl), 2)     AS profit,
                    uniqExact(s.zakaz_uid)      AS orders,
                    round(max(c.ltv), 2)        AS ltv,
                    max(c.lifetime_orders)      AS lifetime_orders,
                    min(c.dney_s_pokupki)       AS days_since_purchase
                FROM {sales} AS s
                LEFT JOIN {customers} AS c ON c.klient_id = s.klient_id
                WHERE {$where}
                GROUP BY name
                ORDER BY revenue DESC
                LIMIT :limit
            SQL, $bindings);

            return array_map(static function (array $row): array {
                $revenue = (float) $row['revenue'];
                $orders = (int) $row['orders'];

                return [
                    'name' => ((string) $row['name']) !== '' ? (string) $row['name'] : '(не указан)',
                    'segment' => (string) $row['segment'],
                    'rfm' => (string) $row['rfm'],
                    'revenue' => $revenue,
                    'profit' => (float) $row['profit'],
                    'margin' => $revenue > 0 ? round((float) $row['profit'] / $revenue * 100, 1) : 0.0,
                    'orders' => $orders,
                    'average_order' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
                    'ltv' => (float) $row['ltv'],
                    'lifetime_orders' => (int) $row['lifetime_orders'],
                    'days_since_purchase' => (int) $row['days_since_purchase'],
                ];
            }, $rows);
        });
    }

    /**
     * Отбор клиентских отчётов: период, пользовательские фильтры и вычет
     * анонимной розницы.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function scope(ReportFilters $filters): array
    {
        $conditions = $filters->conditions();

        return [
            's.data BETWEEN :from AND :to AND s.is_anonymous = 0'.$conditions['sql'],
            [...$filters->period->bindings(), ...$conditions['bindings']],
        ];
    }

    private function boundedLimit(int $limit): int
    {
        return max(1, min($limit, (int) config('analytics.max_top_limit')));
    }

    /** Через сколько дней молчания возвращение считается возвращением. */
    private function pause(): int
    {
        return (int) config('analytics.pause_days');
    }
}