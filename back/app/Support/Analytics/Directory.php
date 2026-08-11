<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * Списки, из которых собираются фильтры, и дата, по которую доехала витрина.
 *
 * Значения берутся из самой витрины, а не из справочников 1С, и это осознанно.
 * В справочнике контрагентов лежат сотни менеджеров, включая уволенных
 * позапрошлой весной; в фильтре нужны те, у кого за последний год была хоть
 * одна продажа. Список, где половина строк ничего не отфильтрует, — плохой
 * список.
 */
final readonly class Directory
{
    /** За какой срок берутся значения, чтобы список не зарастал прошлым. */
    private const LOOKBACK_DAYS = 365;

    public function __construct(private ClickHouse $clickhouse) {}

    /**
     * Всё, что нужно интерфейсу для панели фильтров, одним ответом.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->clickhouse->remember('directory.options', fn (): array => [
            'channels' => $this->values('kanal'),
            'warehouses' => $this->values('sklad'),
            'managers' => $this->values('menedzher'),
            'segments' => $this->values('segment_klienta'),
            'periods' => Period::presetOptions(),
            'granularities' => Period::granularities(),
            'dimensions' => SalesReport::dimensionOptions(),
            'product_dimensions' => ProductReport::dimensionOptions(),
            'freshness' => $this->freshness(),
        ]);
    }

    /**
     * Значения одной колонки с оборотом за ними — чтобы интерфейс мог
     * поставить крупные склады выше мелких, а не по алфавиту.
     *
     * @return list<array{value: string, revenue: float}>
     */
    private function values(string $column): array
    {
        $rows = $this->clickhouse->select(<<<SQL
            SELECT
                {$column}               AS value,
                round(sum(summa), 2)    AS revenue
            FROM {db}.sales_full
            WHERE data >= today() - :lookback
              AND {$column} != ''
            GROUP BY value
            ORDER BY revenue DESC
        SQL, ['lookback' => self::LOOKBACK_DAYS]);

        return array_map(static fn (array $row): array => [
            'value' => (string) $row['value'],
            'revenue' => (float) $row['revenue'],
        ], $rows);
    }

    /**
     * По какое число доехала витрина.
     *
     * Показывается рядом с цифрами: выгрузка приходит ночью, и в течение дня
     * самая свежая продажа — вчерашняя. Человек должен видеть это сам, а не
     * выяснять, почему сегодняшняя отгрузка не сходится с отчётом.
     *
     * @return list<array{source: string, label: string, last_date: string, age_days: int}>
     */
    private function freshness(): array
    {
        $row = $this->clickhouse->selectOne(<<<SQL
            SELECT
                toString(max(data))                     AS last_date,
                dateDiff('day', max(data), today())     AS age_days
            FROM {db}.sales_full
        SQL);

        return [[
            'source' => 'sales',
            'label' => 'Продажи',
            'last_date' => (string) ($row['last_date'] ?? ''),
            'age_days' => (int) ($row['age_days'] ?? 0),
        ]];
    }
}