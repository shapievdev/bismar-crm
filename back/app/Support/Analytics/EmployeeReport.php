<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * Продажи по сотрудникам: кто сколько наторговал и на чём заработал.
 *
 * Отдельно от `SalesReport`, хотя считает те же рубли, потому что отвечает на
 * другой вопрос. Там спрашивают, что происходит с торговлей; здесь — что
 * происходит с людьми, которые её делают, и поэтому знаменателем всюду стоит
 * человек: выручка на сотрудника, средний заказ сотрудника, сколько клиентов он
 * за период обслужил.
 *
 * Сотрудник собирается из двух полей витрины сразу (см. `Mart::sales()`): в
 * чеке торгует продавец, в накладной — ответственный. Рейтинг по любому из них
 * поодиночке потерял бы половину торговли, причём молча: по ответственному не
 * видно розницы, по продавцу — отгрузки.
 *
 * Строки без человека отчёт не прячет и не приписывает никому. Их немного —
 * доли процента, почти всё возвраты без основания, — но «ничьё» и «ноль» это
 * разные вещи, и сводка показывает их отдельной величиной.
 *
 * В именах встречаются кассы и учётные записи, а не только люди: так торговля
 * заведена в 1С, и сводить их к живым сотрудникам отчёт не вправе — он
 * показывает то, под чьим именем прошла продажа.
 */
final readonly class EmployeeReport
{
    public function __construct(private ClickHouse $clickhouse) {}

    /**
     * Итоги за период и то же самое за предыдущий.
     *
     * @return array{current: array<string, float|int>, previous: array<string, float|int>}
     */
    public function summary(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('employees.summary:'.$filters->signature(), fn (): array => [
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

        // Отбор по сотруднику стоит в самих величинах, а не в условии: иначе
        // неприписанную выручку пришлось бы спрашивать вторым запросом, а
        // показать её надо — молчаливая разница с вкладкой продаж читалась бы
        // как ошибка в одной из них.
        $row = $this->clickhouse->selectOne(<<<SQL
            SELECT
                round(sumIf(s.summa, s.sotrudnik != ''), 2)                                     AS revenue,
                round(sumIf(s.pribyl, s.sotrudnik != ''), 2)                                    AS profit,
                round(sumIf(s.summa, s.sotrudnik != '' AND s.bez_sebestoimosti = 1), 2)         AS revenue_without_cost,
                round(sumIf(s.summa, s.sotrudnik = ''), 2)                                      AS revenue_unattributed,
                uniqExactIf(s.zakaz_uid, s.sotrudnik != '')                                     AS orders,
                uniqExactIf(s.sotrudnik, s.sotrudnik != '')                                     AS people,
                uniqExactIf(s.klient_id, s.sotrudnik != '' AND s.is_anonymous = 0)              AS customers
            FROM {sales} AS s
            WHERE {$where}
        SQL, $bindings);

        $revenue = (float) ($row['revenue'] ?? 0);
        $profit = (float) ($row['profit'] ?? 0);
        $orders = (int) ($row['orders'] ?? 0);
        $people = (int) ($row['people'] ?? 0);

        return [
            'revenue' => $revenue,
            'profit' => $profit,
            'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0.0,
            'without_cost_share' => $revenue > 0
                ? round((float) ($row['revenue_without_cost'] ?? 0) / $revenue * 100, 1)
                : 0.0,
            'revenue_unattributed' => (float) ($row['revenue_unattributed'] ?? 0),
            'orders' => $orders,
            'average_order' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
            'people' => $people,
            'customers' => (int) ($row['customers'] ?? 0),
            // Средние на человека, а не на строку: у оптовика строк в десять
            // раз больше, и среднее по ним говорило бы о размере накладной.
            'revenue_per_person' => $people > 0 ? round($revenue / $people, 2) : 0.0,
            'orders_per_person' => $people > 0 ? round($orders / $people, 2) : 0.0,
        ];
    }

    /**
     * Все, под чьим именем прошла продажа, — от крупнейшего к меньшему.
     *
     * Отдаётся списком целиком, а не первой десяткой: отчёт по сотрудникам
     * читают не только сверху. Предел — общий для рейтингов, и его хватает:
     * торгующих имён в витрине меньше сотни.
     *
     * @return list<array<string, float|int|string>>
     */
    public function ranking(ReportFilters $filters, int $limit): array
    {
        $limit = $this->boundedLimit($limit);

        return $this->clickhouse->remember(
            "employees.ranking:{$limit}:".$filters->signature(),
            fn (): array => $this->rows($filters, $limit),
        );
    }

    /**
     * То же, но без предела — всё, под чьим именем за срок прошла продажа.
     *
     * Предел здесь не нужен и вреден: на этот список опираются сопоставление
     * продавцов с отделами и отчёт план-факт, а обрезанный он молча потерял бы
     * хвост — тех, кто продал мало, но продал. Строк столько, сколько имён
     * торгует: группировка по продавцу сама не даёт списку расти.
     *
     * @return list<array<string, float|int|string>>
     */
    public function everyone(ReportFilters $filters): array
    {
        return $this->clickhouse->remember(
            'employees.everyone:'.$filters->signature(),
            fn (): array => $this->rows($filters, null),
        );
    }

    /**
     * @return list<array<string, float|int|string>>
     */
    private function rows(ReportFilters $filters, ?int $limit): array
    {
        [$where, $bindings] = $this->scope($filters);

        $cap = '';

        if ($limit !== null) {
            $cap = 'LIMIT :limit';
            $bindings['limit'] = $limit;
        }

        $rows = $this->clickhouse->select(<<<SQL
            SELECT
                s.sotrudnik                                         AS name,
                round(sum(s.summa), 2)                              AS revenue,
                round(sum(s.pribyl), 2)                             AS profit,
                round(sumIf(s.summa, s.bez_sebestoimosti = 1), 2)   AS revenue_without_cost,
                uniqExact(s.zakaz_uid)                              AS orders,
                count()                                             AS lines,
                uniqExactIf(s.klient_id, s.is_anonymous = 0)        AS customers
            FROM {sales} AS s
            WHERE {$where}
              AND s.sotrudnik != ''
            GROUP BY name
            ORDER BY revenue DESC
            {$cap}
        SQL, $bindings);

        return array_map(static function (array $row): array {
            $revenue = (float) $row['revenue'];
            $profit = (float) $row['profit'];
            $orders = (int) $row['orders'];

            return [
                'name' => (string) $row['name'],
                'revenue' => $revenue,
                'profit' => $profit,
                'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0.0,
                'without_cost_share' => $revenue > 0
                    ? round((float) $row['revenue_without_cost'] / $revenue * 100, 1)
                    : 0.0,
                'orders' => $orders,
                'average_order' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
                // Строк в документе: по ним считается, сколько позиций уносят
                // за одну покупку, — у консультанта это и есть его работа.
                'lines' => (int) $row['lines'],
                'lines_per_order' => $orders > 0 ? round((int) $row['lines'] / $orders, 2) : 0.0,
                'customers' => (int) $row['customers'],
            ];
        }, $rows);
    }

    /**
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

    private function boundedLimit(int $limit): int
    {
        return max(1, min($limit, (int) config('analytics.max_top_limit')));
    }
}
