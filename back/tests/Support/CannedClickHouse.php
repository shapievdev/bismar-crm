<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Analytics\ClickHouse;

/**
 * ClickHouse, отвечающий заготовленным.
 *
 * Отличается от `FakeClickHouse` назначением, а не устройством. Там сервер
 * аналитики подменён пустым, потому что проверяются права и разбор запроса, и
 * выдуманные обороты были бы проверкой собственной выдумки. Здесь же выручка —
 * это **вход** проверяемого правила: план по умолчанию считается из неё, и без
 * неё проверять нечего.
 *
 * Поэтому ответ один на все запросы: правила, которые он обслуживает, задают
 * витрине ровно один вопрос — сколько наторговал каждый продавец.
 */
final class CannedClickHouse extends ClickHouse
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function __construct(private readonly array $rows) {}

    /**
     * Выручка по именам продавцов, как её отдаёт витрина.
     *
     * @param  array<string, float>  $revenueByName
     */
    public static function withSellers(array $revenueByName): self
    {
        $rows = [];

        foreach ($revenueByName as $name => $revenue) {
            $rows[] = [
                'name' => $name,
                'revenue' => $revenue,
                'profit' => $revenue * 0.2,
                'revenue_without_cost' => 0,
                'orders' => 10,
                'lines' => 25,
                'customers' => 5,
            ];
        }

        return new self($rows);
    }

    /**
     * @param  array<string, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->rows;
    }

    /**
     * @param  array<string, mixed>  $bindings
     * @return array<string, mixed>
     */
    public function selectOne(string $sql, array $bindings = []): array
    {
        return $this->rows[0] ?? [];
    }
}
