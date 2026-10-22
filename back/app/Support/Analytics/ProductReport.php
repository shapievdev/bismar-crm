<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use InvalidArgumentException;

/**
 * Товары: что продаётся, что лежит и на чём зарабатывают.
 *
 * Разметка ABC/XYZ сделана витриной на годовом окне, а не на окне отчёта, — и
 * это единственный способ её получить. Посчитанная по выбранному месяцу,
 * категория A означала бы «продавалось в этом месяце», а X («ровный спрос»)
 * вообще не имеет смысла на отрезке короче года. Лежит она отдельной таблицей,
 * и отчёт присоединяет её по товару (см. `Mart::products()`).
 *
 * ABC — про долю в обороте: A даёт основную выручку, C замыкает хвост.
 * XYZ — про ровность спроса: X берут стабильно, Z берут случайно. Вместе они
 * отвечают на вопрос, что держать на складе всегда, а что возить под заказ.
 *
 * Классов в разметке больше трёх на ось: витрина отдельно помечает новинки,
 * товары с короткой историей наблюдений и те, что за год не продавались, —
 * и в девять клеток это не укладывается. Поэтому матрица собирается по тем
 * меткам, которые в периоде действительно встретились, а не по заранее
 * известной сетке: пустая клетка — факт, а выброшенная метка — ложь.
 */
final readonly class ProductReport
{
    public function __construct(private ClickHouse $clickhouse) {}

    /** Буквенные классы идут первыми: с них читают матрицу. */
    private const ABC_ORDER = ['A', 'B', 'C'];

    private const XYZ_ORDER = ['X', 'Y', 'Z'];

    /**
     * Матрица ABC × XYZ: клетки, в которых лежит размеченный ассортимент.
     *
     * @return list<array<string, float|int|string>>
     */
    public function matrix(ReportFilters $filters): array
    {
        return $this->clickhouse->remember('products.matrix:'.$filters->signature(), function () use ($filters): array {
            [$where, $bindings] = $this->scope($filters);

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    p.abc                       AS abc,
                    p.xyz                       AS xyz,
                    round(sum(s.summa), 2)      AS revenue,
                    round(sum(s.pribyl), 2)     AS profit,
                    uniqExactIf(s.sku, s.sku != '') AS items
                FROM {sales} AS s
                LEFT JOIN {products} AS p ON p.sku = s.sku
                WHERE {$where}
                  AND p.abc != ''
                  AND p.xyz != ''
                GROUP BY abc, xyz
            SQL, $bindings);

            $byCell = [];

            foreach ($rows as $row) {
                $byCell[$row['abc']."\t".$row['xyz']] = $row;
            }

            // Пустые клетки возвращаются нулями: отсутствие продаж в клетке —
            // такой же факт, как их наличие, и дырка в сетке читается хуже нуля.
            $matrix = [];

            foreach ($this->labels($rows, 'abc', self::ABC_ORDER) as $abc) {
                foreach ($this->labels($rows, 'xyz', self::XYZ_ORDER) as $xyz) {
                    $row = $byCell[$abc."\t".$xyz] ?? null;

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
     * Метки одной оси: сначала известные классы в своём порядке, затем
     * остальные по алфавиту.
     *
     * Список собирается из ответа, а не задаётся заранее: витрина вольна
     * завести новую метку, и правильная матрица — та, которая её покажет.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $known
     * @return list<string>
     */
    private function labels(array $rows, string $axis, array $known): array
    {
        $present = array_unique(array_map(static fn (array $row): string => (string) $row[$axis], $rows));

        $ordered = array_values(array_intersect($known, $present));
        $rest = array_values(array_diff($present, $known));
        sort($rest);

        return [...$ordered, ...$rest];
    }

    /**
     * Разрезы товарных рейтингов.
     *
     * Список закрытый: колонка попадает в SQL склейкой, и принимать её имя от
     * клиента нельзя. Разрез по матрице — единственный, которому нужна
     * присоединённая разметка; остальным хватает самой строки продажи, и
     * присоединять её им значило бы читать лишнюю таблицу.
     */
    private const DIMENSIONS = [
        'item' => ['column' => 's.tovar', 'label' => 'Товар'],
        'brand' => ['column' => 's.brend', 'label' => 'Бренд'],
        'group' => ['column' => 's.gruppa1', 'label' => 'Группа'],
        'segment' => ['column' => 's.segment_tovara', 'label' => 'Сегмент'],
        // Товар без разметки даёт пустое имя, а не « / »: строкой рейтинга
        // он станет как «(не указано)», наравне с незаполненным в 1С.
        'matrix' => [
            'column' => "if(p.abc = '' AND p.xyz = '', '', concat(p.abc, ' / ', p.xyz))",
            'label' => 'Матрица',
            'marked' => true,
        ],
    ];

    /**
     * Рейтинг по одному товарному разрезу.
     *
     * @return list<array<string, float|int|string>>
     */
    public function breakdown(ReportFilters $filters, string $dimension, int $limit): array
    {
        $meta = self::DIMENSIONS[$dimension]
            ?? throw new InvalidArgumentException("Неизвестный разрез: {$dimension}");

        $column = $meta['column'];
        $join = ($meta['marked'] ?? false) ? 'LEFT JOIN {products} AS p ON p.sku = s.sku' : '';

        $limit = $this->boundedLimit($limit);
        $key = "products.breakdown:{$dimension}:{$limit}:".$filters->signature();

        return $this->clickhouse->remember($key, function () use ($filters, $column, $join, $limit): array {
            [$where, $bindings] = $this->scope($filters);
            $bindings['limit'] = $limit;

            $rows = $this->clickhouse->select(<<<SQL
                SELECT
                    {$column}                   AS name,
                    round(sum(s.summa), 2)      AS revenue,
                    round(sum(s.pribyl), 2)     AS profit,
                    round(sum(s.kolvo), 3)      AS quantity,
                    uniqExact(s.zakaz_uid)      AS orders,
                    uniqExactIf(s.sku, s.sku != '') AS items
                FROM {sales} AS s
                {$join}
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
                    uniqExactIf(s.sku, s.sku != '') AS items,
                    round(sum(s.kolvo), 3)      AS quantity
                FROM {sales} AS s
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