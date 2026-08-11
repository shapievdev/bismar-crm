<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use InvalidArgumentException;

/**
 * Товары: что продаётся, что лежит и на чём зарабатывают.
 *
 * Разметка ABC/XYZ, неликвида и товарной матрицы сделана витриной на всей
 * истории, а не на окне отчёта, — и это единственный способ её получить.
 * Посчитанная по выбранному месяцу, категория A означала бы «продавалось в
 * этом месяце», а X («ровный спрос») вообще не имеет смысла на отрезке короче
 * года.
 *
 * ABC — про долю в обороте: A даёт основную выручку, C замыкает хвост.
 * XYZ — про ровность спроса: X берут стабильно, Z берут случайно. Вместе они
 * отвечают на вопрос, что держать на складе всегда, а что возить под заказ.
 */
final readonly class ProductReport
{
    public function __construct(private ClickHouse $clickhouse) {}

    /**
     * Матрица ABC × XYZ: девять клеток, в которых лежит весь ассортимент.
     *
     * @return list<array<string, float|int|string>>
     */
    public function matrix(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('products.matrix:'.$filters->signature(), function () use ($filters): array {
            [$where, $bindings] = $this->scope($filters);

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    s.abc                       AS abc,
                    s.xyz                       AS xyz,
                    round(sum(s.summa), 2)      AS revenue,
                    round(sum(s.pribyl), 2)     AS profit,
                    uniqExact(s.sku)            AS items
                FROM {db}.sales_full AS s
                WHERE {$where}
                  AND s.abc IN ('A', 'B', 'C')
                  AND s.xyz IN ('X', 'Y', 'Z')
                GROUP BY abc, xyz
            SQL, $bindings);

            $byCell = [];

            foreach ($rows as $row) {
                $byCell[$row['abc'].$row['xyz']] = $row;
            }

            // Пустые клетки возвращаются нулями: в матрице девять мест, и
            // отсутствие товаров в клетке — такой же факт, как их наличие.
            $matrix = [];

            foreach (['A', 'B', 'C'] as $abc) {
                foreach (['X', 'Y', 'Z'] as $xyz) {
                    $row = $byCell[$abc.$xyz] ?? null;

                    $matrix[] = [
                        'abc' => $abc,
                        'xyz' => $xyz,
                        'revenue' => (float) ($row['revenue'] ?? 0),
                        'profit' => (float) ($row['profit'] ?? 0),
                        'items' => (int) ($row['items'] ?? 0),
                    ];
                }
            }

            return $matrix;
        });
    }

    /**
     * Разрезы товарных рейтингов.
     *
     * Список закрытый: колонка попадает в SQL склейкой, и принимать её имя от
     * клиента нельзя.
     */
    private const DIMENSIONS = [
        'item' => ['column' => 's.tovar', 'label' => 'Товар'],
        'brand' => ['column' => 's.brend', 'label' => 'Бренд'],
        'group' => ['column' => 's.gruppa1', 'label' => 'Группа'],
        'segment' => ['column' => 's.segment_tovara', 'label' => 'Сегмент'],
        'matrix' => ['column' => 's.matrica', 'label' => 'Матрица'],
    ];

    /**
     * Рейтинг по одному товарному разрезу.
     *
     * @return list<array<string, float|int|string>>
     */
    public function breakdown(ReportFilters $filters, string $dimension, int $limit): array
    {
        $column = self::DIMENSIONS[$dimension]['column']
            ?? throw new InvalidArgumentException("Неизвестный разрез: {$dimension}");

        $limit = $this->boundedLimit($limit);
        $key = "products.breakdown:{$dimension}:{$limit}:".$filters->signature();

        return $this->clickhouse->remember($key, function () use ($filters, $column, $limit): array {
            [$where, $bindings] = $this->scope($filters);
            $bindings['limit'] = $limit;

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    {$column}                   AS name,
                    round(sum(s.summa), 2)      AS revenue,
                    round(sum(s.pribyl), 2)     AS profit,
                    round(sum(s.kolvo), 3)      AS quantity,
                    uniqExact(s.zakaz_uid)      AS orders,
                    uniqExact(s.sku)            AS items
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
                    'name' => ((string) $row['name']) !== '' ? (string) $row['name'] : '(не указано)',
                    'revenue' => $revenue,
                    'profit' => $profit,
                    'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0.0,
                    'quantity' => (float) $row['quantity'],
                    'orders' => (int) $row['orders'],
                    'items' => (int) $row['items'],
                ];
            }, $rows);
        });
    }

    /**
     * Неликвид: сколько его всё-таки продаётся.
     *
     * Метка стоит на товаре, а не на продаже, поэтому строки здесь означают
     * «продажи того, что считается неликвидом», а не «залежавшийся запас».
     * Вопрос, на который отвечает панель, — уходит ли помеченное вообще.
     *
     * @return list<array<string, float|int|string>>
     */
    public function illiquid(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('products.illiquid:'.$filters->signature(), function () use ($filters): array {
            [$where, $bindings] = $this->scope($filters);

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    s.nelikvid                  AS status,
                    round(sum(s.summa), 2)      AS revenue,
                    round(sum(s.pribyl), 2)     AS profit,
                    uniqExact(s.sku)            AS items,
                    round(sum(s.kolvo), 3)      AS quantity
                FROM {db}.sales_full AS s
                WHERE {$where}
                GROUP BY status
                ORDER BY revenue DESC
            SQL, $bindings);

            return array_map(static function (array $row): array {
                $revenue = (float) $row['revenue'];

                return [
                    'status' => ((string) $row['status']) !== '' ? (string) $row['status'] : '(не размечен)',
                    'revenue' => $revenue,
                    'profit' => (float) $row['profit'],
                    'margin' => $revenue > 0 ? round((float) $row['profit'] / $revenue * 100, 1) : 0.0,
                    'items' => (int) $row['items'],
                    'quantity' => (float) $row['quantity'],
                ];
            }, $rows);
        });
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