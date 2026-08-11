<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * Клиенты: кто покупает, кто вернулся и кто перестал.
 *
 * Всё, что здесь считается, витрина уже разметила — RFM, когорту, номер заказа
 * в жизни клиента, LTV. Пересчитывать это запросом бессмысленно и опасно:
 * разметка сделана на всей истории, а отчёт видит только выбранный период, и
 * «новым» в нём оказался бы всякий, кто не покупал последний месяц.
 *
 * Из всех метрик вычтена анонимная розница. За ней стоит один технический
 * контрагент, которым витрина сводит покупки без карты, — в выручке эти деньги
 * настоящие, но клиентом эта строка не является. Оставленная в подсчёте, она
 * даёт «одного клиента» с оборотом в сотню миллионов, первое место в списке
 * лучших и средний LTV, поделённый не на то число.
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

        $row = $this->clickhouse->selectOne(<<<SQL
            SELECT
                uniqExact(s.klient_id)                                      AS customers,
                -- Первый заказ в жизни клиента размечен витриной на всей
                -- истории, а не на окне отчёта.
                uniqExactIf(s.klient_id, s.pervyj_zakaz = 1)                AS new_customers,
                uniqExactIf(s.klient_id, s.tip_zakaza = 'Вернулся после паузы') AS revived_customers,
                round(sum(s.summa), 2)                                      AS revenue,
                round(sum(s.pribyl), 2)                                     AS profit,
                uniqExact(s.zakaz_uid)                                      AS orders
            FROM {db}.sales_full AS s
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
     * RFM: во что сложились давность, частота и деньги.
     *
     * Порядок задан самой разметкой — сегменты в витрине пронумерованы
     * («1. Лучшие», «5. Засыпают»), и сортировка по имени выстраивает их от
     * лучших к потерянным.
     *
     * @return list<array<string, float|int|string>>
     */
    public function segments(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('customers.rfm:'.$filters->signature(), function () use ($filters): array {
            [$where, $bindings] = $this->scope($filters);

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    s.rfm_segment               AS segment,
                    uniqExact(s.klient_id)      AS customers,
                    round(sum(s.summa), 2)      AS revenue,
                    round(sum(s.pribyl), 2)     AS profit,
                    uniqExact(s.zakaz_uid)      AS orders
                FROM {db}.sales_full AS s
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
     * @return list<array<string, float|int|string>>
     */
    public function orderTypes(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('customers.types:'.$filters->signature(), function () use ($filters): array {
            [$where, $bindings] = $this->scope($filters);

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    s.tip_zakaza                AS type,
                    uniqExact(s.zakaz_uid)      AS orders,
                    uniqExact(s.klient_id)      AS customers,
                    round(sum(s.summa), 2)      AS revenue
                FROM {db}.sales_full AS s
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
     * Берутся только надёжные (`kogorta_nadezhna`) — те, для которых у витрины
     * достаточно истории. Ненадёжная когорта состоит из клиентов, чей первый
     * заказ мог случиться до начала выгрузки, и «новичком» там числится тот,
     * кто покупает десять лет.
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
                    s.kogorta                   AS cohort,
                    uniqExact(s.klient_id)      AS customers,
                    round(sum(s.summa), 2)      AS revenue,
                    uniqExact(s.zakaz_uid)      AS orders
                FROM {db}.sales_full AS s
                WHERE {$where}
                  AND s.kogorta_nadezhna = 1
                  AND s.kogorta != ''
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
     * Крупнейшие клиенты — с тем, что витрина знает о них сверх периода.
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
                    any(s.rfm_segment)          AS rfm,
                    round(sum(s.summa), 2)      AS revenue,
                    round(sum(s.pribyl), 2)     AS profit,
                    uniqExact(s.zakaz_uid)      AS orders,
                    round(max(s.ltv_klienta), 2) AS ltv,
                    max(s.zakazov_klienta)      AS lifetime_orders,
                    min(s.dney_s_pokupki)       AS days_since_purchase
                FROM {db}.sales_full AS s
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

        /** @var string $anonymous */
        $anonymous = config('analytics.anonymous_customer_type');

        return [
            's.data BETWEEN :from AND :to AND s.tip_klienta != :anonymous'.$conditions['sql'],
            [...$filters->period->bindings(), ...$conditions['bindings'], 'anonymous' => $anonymous],
        ];
    }

    private function boundedLimit(int $limit): int
    {
        return max(1, min($limit, (int) config('analytics.max_top_limit')));
    }
}