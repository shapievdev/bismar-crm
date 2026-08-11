<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use InvalidArgumentException;

/**
 * Торговля: сколько наторговали и на чём заработали.
 *
 * Считает по `mart.sales_full` — витрине, где строка есть товар в заказе, а
 * заказ опознаётся по `zakaz_uid`. Из этого следуют две вещи, которые здесь
 * определяют всё:
 *
 * 1. **Заказы считаются уникальными, а не строками.** В заказе из сорока
 *    позиций сорок строк, и `count()` ответил бы на другой вопрос. Средний чек
 *    поэтому делится на `uniqExact(zakaz_uid)`.
 *
 * 2. **Атрибуты заказа продублированы в каждой его строке.** `pozicij`,
 *    `ltv_klienta`, `zakazov_klienta` относятся к заказу и клиенту, а не к
 *    строке; суммировать их по строкам нельзя — получится число позиций,
 *    умноженное на само себя. Такие величины берутся через `any()` внутри
 *    группировки по заказу.
 *
 * Возвраты витрина помечает флагом `vozvrat`, суммы у них уже отрицательные,
 * поэтому в выручку они входят как есть и уменьшают её. Прибыль здесь считать
 * можно прямо: доля строк без себестоимости — около четырёх процентов против
 * четверти в сыром слое.
 */
final readonly class SalesReport
{
    public function __construct(private ClickHouse $clickhouse) {}

    /**
     * Итоги за период и то же самое за предыдущий — для стрелок «сколько это
     * в сравнении с прошлым разом».
     *
     * @return array{current: array<string, float|int>, previous: array<string, float|int>}
     */
    public function summary(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('sales.summary:'.$filters->signature(), fn (): array => [
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
        $bindings['anonymous'] = $this->anonymous();

        $row = $this->clickhouse->selectOne(<<<SQL
            SELECT
                round(sum(s.summa), 2)                                      AS revenue,
                round(sum(s.pribyl), 2)                                     AS profit,
                round(sumIf(s.summa, s.bez_sebestoimosti = 1), 2)           AS revenue_without_cost,
                round(abs(sumIf(s.summa, s.vozvrat = 1)), 2)                AS returns,
                uniqExactIf(s.zakaz_uid, s.vozvrat = 1)                     AS return_orders,
                uniqExact(s.zakaz_uid)                                      AS orders,
                round(sum(s.kolvo), 3)                                      AS quantity,
                count()                                                     AS lines,
                uniqExact(s.sku)                                            AS items,
                -- Клиентов считаем без анонимной розницы: за ней стоит один
                -- технический контрагент, а не покупатель.
                uniqExactIf(s.klient_id, s.tip_klienta != :anonymous)       AS customers
            FROM {db}.sales_full AS s
            WHERE {$where}
        SQL, $bindings);

        $revenue = (float) ($row['revenue'] ?? 0);
        $profit = (float) ($row['profit'] ?? 0);
        $orders = (int) ($row['orders'] ?? 0);
        $returns = (float) ($row['returns'] ?? 0);

        return [
            'revenue' => $revenue,
            'profit' => $profit,
            'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0.0,
            // Насколько верить марже: доля выручки, для которой витрина
            // себестоимости не знает.
            'without_cost_share' => $revenue > 0 ? round((float) ($row['revenue_without_cost'] ?? 0) / $revenue * 100, 1) : 0.0,
            'returns' => $returns,
            'return_orders' => (int) ($row['return_orders'] ?? 0),
            'return_rate' => $revenue > 0 ? round($returns / $revenue * 100, 1) : 0.0,
            'orders' => $orders,
            'average_order' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
            'quantity' => (float) ($row['quantity'] ?? 0),
            'lines' => (int) ($row['lines'] ?? 0),
            'items' => (int) ($row['items'] ?? 0),
            'customers' => (int) ($row['customers'] ?? 0),
        ];
    }

    /**
     * Динамика по точкам графика — шаг задаёт сам период.
     *
     * @return list<array<string, float|int|string>>
     */
    public function trend(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('sales.trend:'.$filters->signature(), function () use ($filters): array {
            [$where, $bindings] = $this->scope($filters);
            $bucket = $filters->period->bucketExpression('s.data');

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    toString({$bucket})         AS bucket,
                    round(sum(s.summa), 2)      AS revenue,
                    round(sum(s.pribyl), 2)     AS profit,
                    uniqExact(s.zakaz_uid)      AS orders
                FROM {db}.sales_full AS s
                WHERE {$where}
                GROUP BY bucket
                ORDER BY bucket
            SQL, $bindings);

            return array_map(static function (array $row): array {
                $revenue = (float) $row['revenue'];
                $orders = (int) $row['orders'];

                return [
                    'bucket' => (string) $row['bucket'],
                    'revenue' => $revenue,
                    'profit' => (float) $row['profit'],
                    'orders' => $orders,
                    'average_order' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
                ];
            }, $rows);
        });
    }

    /**
     * Каналы: накладные против чеков ККМ.
     *
     * @return list<array<string, float|int|string>>
     */
    public function channels(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('sales.channels:'.$filters->signature(), function () use ($filters): array {
            [$where, $bindings] = $this->scope($filters);

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    s.kanal                     AS channel,
                    round(sum(s.summa), 2)      AS revenue,
                    round(sum(s.pribyl), 2)     AS profit,
                    uniqExact(s.zakaz_uid)      AS orders
                FROM {db}.sales_full AS s
                WHERE {$where}
                GROUP BY channel
                ORDER BY revenue DESC
            SQL, $bindings);

            return array_map(static function (array $row): array {
                $revenue = (float) $row['revenue'];
                $profit = (float) $row['profit'];
                $orders = (int) $row['orders'];

                return [
                    'channel' => (string) $row['channel'],
                    'revenue' => $revenue,
                    'profit' => $profit,
                    'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0.0,
                    'orders' => $orders,
                    'average_order' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
                ];
            }, $rows);
        });
    }

    /**
     * Структура выручки по каналам во времени — для столбцов друг на друге.
     *
     * Отдельно от channels(), потому что отвечает на другой вопрос: не «сколько
     * дал каждый канал», а «менялось ли соотношение». Столбец целиком — выручка
     * периода, доли внутри — каналы.
     *
     * @return list<array{bucket: string, channel: string, revenue: float}>
     */
    public function channelTrend(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('sales.channel-trend:'.$filters->signature(), function () use ($filters): array {
            [$where, $bindings] = $this->scope($filters);
            $bucket = $filters->period->bucketExpression('s.data');

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    toString({$bucket})     AS bucket,
                    s.kanal                 AS channel,
                    round(sum(s.summa), 2)  AS revenue
                FROM {db}.sales_full AS s
                WHERE {$where}
                GROUP BY bucket, channel
                ORDER BY bucket, channel
            SQL, $bindings);

            return array_map(static fn (array $row): array => [
                'bucket' => (string) $row['bucket'],
                'channel' => (string) $row['channel'],
                'revenue' => (float) $row['revenue'],
            ], $rows);
        });
    }

    /**
     * Тот же период год… нет — предыдущий такой же, наложенный на текущий.
     *
     * Точки сопоставляются по порядковому номеру дня внутри периода, а не по
     * дате: у мая и июня разное число дней, и наложение по календарю оставило
     * бы хвост без пары. Номер дня даёт честное «первый день против первого».
     *
     * @return list<array{offset: int, label: string, current: float, previous: float}>
     */
    public function comparison(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('sales.comparison:'.$filters->signature(), function () use ($filters): array {
            $current = $this->dailyRevenue($filters);
            $previous = $this->dailyRevenue($filters->withPeriod($filters->period->previous()));

            $length = max(count($current), count($previous));
            $points = [];

            for ($offset = 0; $offset < $length; $offset++) {
                $points[] = [
                    'offset' => $offset,
                    // Подпись берётся у текущего периода: он на экране главный.
                    'label' => $current[$offset]['date'] ?? '',
                    'current' => (float) ($current[$offset]['revenue'] ?? 0),
                    'previous' => (float) ($previous[$offset]['revenue'] ?? 0),
                ];
            }

            return $points;
        });
    }

    /**
     * Выручка по дням отрезка, по порядку.
     *
     * @return list<array{date: string, revenue: float}>
     */
    private function dailyRevenue(ReportFilters $filters): array
    {
        [$where, $bindings] = $this->scope($filters);

        $rows = $this->clickhouse->select(<<<SQL
            SELECT
                toString(s.data)        AS date,
                round(sum(s.summa), 2)  AS revenue
            FROM {db}.sales_full AS s
            WHERE {$where}
            GROUP BY date
            ORDER BY date
        SQL, $bindings);

        return array_map(static fn (array $row): array => [
            'date' => (string) $row['date'],
            'revenue' => (float) $row['revenue'],
        ], $rows);
    }

    /**
     * Профиль недели: какой день кормит торговлю.
     *
     * Считается в среднем за один такой день, а не суммой: в периоде вторников
     * может быть тринадцать, а понедельников двенадцать, и сумма сравнивала бы
     * не дни недели, а их количество в отрезке.
     *
     * @return list<array{weekday: int, label: string, revenue: float, orders: int}>
     */
    public function weekdayProfile(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('sales.weekday:'.$filters->signature(), function () use ($filters): array {
            [$where, $bindings] = $this->scope($filters);

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    toDayOfWeek(s.data)     AS weekday,
                    round(sum(s.summa), 2)  AS revenue,
                    uniqExact(s.zakaz_uid)  AS orders,
                    uniqExact(s.data)       AS days
                FROM {db}.sales_full AS s
                WHERE {$where}
                GROUP BY weekday
                ORDER BY weekday
            SQL, $bindings);

            $labels = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

            return array_map(static function (array $row) use ($labels): array {
                $days = max(1, (int) $row['days']);

                return [
                    'weekday' => (int) $row['weekday'],
                    'label' => $labels[(int) $row['weekday']] ?? '—',
                    'revenue' => round((float) $row['revenue'] / $days, 2),
                    'orders' => (int) round((int) $row['orders'] / $days),
                ];
            }, $rows);
        });
    }

    /**
     * Из чего складывается прибыль: выручка минус себестоимость.
     *
     * Три величины, которые сходятся арифметически (проверено на витрине:
     * `sum(summa) - sum(sebestoimost) = sum(pribyl)` до копейки), и потому
     * рисуются водопадом, а не тремя отдельными числами.
     *
     * @return array{revenue: float, cost: float, profit: float, margin: float}
     */
    public function costStructure(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('sales.cost-structure:'.$filters->signature(), function () use ($filters): array {
            [$where, $bindings] = $this->scope($filters);

            $row = $this->clickhouse->selectOne(<<<SQL
                SELECT
                    round(sum(s.summa), 2)          AS revenue,
                    round(sum(s.sebestoimost), 2)   AS cost,
                    round(sum(s.pribyl), 2)         AS profit
                FROM {db}.sales_full AS s
                WHERE {$where}
            SQL, $bindings);

            $revenue = (float) ($row['revenue'] ?? 0);
            $profit = (float) ($row['profit'] ?? 0);

            return [
                'revenue' => $revenue,
                'cost' => (float) ($row['cost'] ?? 0),
                'profit' => $profit,
                'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0.0,
            ];
        });
    }

    /**
     * Разрезы, по которым интерфейс строит рейтинги.
     *
     * Ключ — то, что присылает клиент; значение — колонка витрины. Список
     * закрытый, потому что колонка попадает в SQL склейкой: принять имя поля от
     * пользователя означало бы отдать ему весь сервер.
     */
    private const DIMENSIONS = [
        'item' => ['column' => 's.tovar', 'label' => 'Товар'],
        'manager' => ['column' => 's.menedzher', 'label' => 'Менеджер'],
        // Единственный разрез, из которого анонимная розница вычитается. В
        // остальных она — честная строка: «столько-то оборота прошло без
        // менеджера, без карты, через этот склад». А вот список клиентов она
        // возглавляет, не будучи клиентом, и вытесняет из него настоящего
        // первого.
        'customer' => ['column' => 's.klient', 'label' => 'Клиент', 'named_only' => true],
        'warehouse' => ['column' => 's.sklad', 'label' => 'Склад'],
        'seller' => ['column' => 's.prodavec', 'label' => 'Продавец'],
        'direction' => ['column' => 's.napravlenie', 'label' => 'Направление'],
        'organisation' => ['column' => 's.organizaciya', 'label' => 'Организация'],
    ];

    /**
     * Рейтинг по одному разрезу: кто и сколько принёс.
     *
     * @return list<array<string, float|int|string>>
     */
    public function breakdown(ReportFilters $filters, string $dimension, int $limit): array
    {
        $meta = self::DIMENSIONS[$dimension]
            ?? throw new InvalidArgumentException("Неизвестный разрез: {$dimension}");

        $column = $meta['column'];
        $namedOnly = $meta['named_only'] ?? false;

        $limit = $this->boundedLimit($limit);
        $key = "sales.breakdown:{$dimension}:{$limit}:".$filters->signature();

        return $this->clickhouse->remember($key, function () use ($filters, $column, $namedOnly, $limit): array {
            [$where, $bindings] = $this->scope($filters);
            $bindings['limit'] = $limit;

            if ($namedOnly) {
                $where .= ' AND s.tip_klienta != :anonymous';
                $bindings['anonymous'] = $this->anonymous();
            }

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    {$column}                                           AS name,
                    round(sum(s.summa), 2)                              AS revenue,
                    round(sum(s.pribyl), 2)                             AS profit,
                    round(sumIf(s.summa, s.bez_sebestoimosti = 1), 2)   AS revenue_without_cost,
                    round(sum(s.kolvo), 3)                              AS quantity,
                    uniqExact(s.zakaz_uid)                              AS orders
                FROM {db}.sales_full AS s
                WHERE {$where}
                GROUP BY name
                ORDER BY revenue DESC
                LIMIT :limit
            SQL, $bindings);

            return array_map(static function (array $row): array {
                $revenue = (float) $row['revenue'];
                $profit = (float) $row['profit'];

                return [
                    // Пустое значение в 1С — не «нет данных», а «не заполнили».
                    'name' => ((string) $row['name']) !== '' ? (string) $row['name'] : '(не указано)',
                    'revenue' => $revenue,
                    'profit' => $profit,
                    'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0.0,
                    'without_cost_share' => $revenue > 0
                        ? round((float) $row['revenue_without_cost'] / $revenue * 100, 1)
                        : 0.0,
                    'quantity' => (float) $row['quantity'],
                    'orders' => (int) $row['orders'],
                ];
            }, $rows);
        });
    }

    /**
     * Общий отбор: период плюс пользовательские фильтры.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function scope(ReportFilters $filters): array
    {
        $conditions = $filters->conditions();

        return [
            's.data BETWEEN :from AND :to'.$conditions['sql'],
            [...$filters->period->bindings(), ...$conditions['bindings']],
        ];
    }

    private function anonymous(): string
    {
        /** @var string $type */
        $type = config('analytics.anonymous_customer_type');

        return $type;
    }

    private function boundedLimit(int $limit): int
    {
        return max(1, min($limit, (int) config('analytics.max_top_limit')));
    }

    /**
     * Имена разрезов, которые вообще существуют.
     *
     * Маршрут сверяется с этим списком, поэтому неизвестное значение не
     * доходит ни до отчёта, ни до склейки SQL.
     *
     * @return list<string>
     */
    public static function dimensions(): array
    {
        return array_keys(self::DIMENSIONS);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function dimensionOptions(): array
    {
        return array_map(
            static fn (string $value, array $meta): array => ['value' => $value, 'label' => $meta['label']],
            array_keys(self::DIMENSIONS),
            array_values(self::DIMENSIONS),
        );
    }
}