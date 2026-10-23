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
    /**
     * @param  array<string, float>  $named
     */
    private function __construct(
        private readonly int $rows,
        private readonly array $named = [],
    ) {}

    /** Столько строк в раскладке — чтобы было что мерить высотой панели. */
    public static function withRows(int $rows): self
    {
        return new self($rows);
    }

    /**
     * Поимённая выручка — так, как её отдаёт витрина: строками из 1С.
     *
     * Нужна там, где проверяют структуру компании: сопоставление превращает
     * эти строки в сотрудников и отделы, и подменить их безымянными «Склад №1»
     * значило бы проверять не то.
     *
     * @param  array<string, float>  $revenueByName
     */
    public static function withEmployees(array $revenueByName): self
    {
        return new self(0, $revenueByName);
    }

    /**
     * @param  array<string, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        // Точки графика — свой запрос и свои поля. Без них панель с линией
        // исчезает: график из одной точки не рисуется.
        if (str_contains($sql, 'AS bucket')) {
            return array_map(static fn (int $day): array => [
                'bucket' => sprintf('2026-10-%02d', $day),
                'revenue' => 1000.0 * $day,
                'profit' => 100.0 * $day,
                'orders' => $day,
            ], range(1, 5));
        }

        if (! str_contains($sql, 'AS name')) {
            return [];
        }

        if ($this->named !== []) {
            return array_map(static fn (string $name, float $revenue): array => [
                'name' => $name,
                'revenue' => $revenue,
                'profit' => $revenue * 0.1,
                'revenue_without_cost' => 0,
                'quantity' => 1.0,
                'orders' => 1,
                'lines' => 1,
                'customers' => 1,
                'items' => 1,
            ], array_keys($this->named), array_values($this->named));
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
