<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Analytics\ClickHouse;

/**
 * ClickHouse, у которого есть что разложить по разрезу.
 *
 * Третий дублёр витрины, и он не от бедности. `FakeClickHouse` отвечает пусто —
 * им проверяют права и разбор запроса. `CannedClickHouse` отвечает одной и той
 * же строкой на любой вопрос, потому что правила, которые он обслуживает,
 * задают витрине ровно один вопрос. Здесь же вопросов два сразу: сперва
 * собирается справочник отбора для подсказки модели, потом считается сам
 * рейтинг, — и ответ «одно на всё» ломает первый из них.
 *
 * Поэтому различает он их по самому запросу: строка рейтинга выбирает `AS name`,
 * справочник — `AS value`. Грубо, но честно — и видно, на что именно отвечает.
 */
final class RankingClickHouse extends ClickHouse
{
    private function __construct(private readonly int $rows) {}

    /** Столько строк в раскладке — чтобы было что мерить высотой панели. */
    public static function withRows(int $rows): self
    {
        return new self($rows);
    }

    /**
     * @param  array<string, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        if (! str_contains($sql, 'AS name')) {
            return [];
        }

        $rows = [];

        for ($i = 1; $i <= $this->rows; $i++) {
            $rows[] = [
                'name' => 'Склад №'.$i,
                'revenue' => 1000.0 * ($this->rows - $i + 1),
                'profit' => 100.0 * ($this->rows - $i + 1),
                'revenue_without_cost' => 0,
                'quantity' => 1.0,
                'orders' => 1,
                'items' => 1,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $bindings
     * @return array<string, mixed>
     */
    public function selectOne(string $sql, array $bindings = []): array
    {
        return $this->select($sql, $bindings)[0] ?? [];
    }
}
