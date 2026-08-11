<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Analytics\AnalyticsUnavailable;
use App\Support\Analytics\ClickHouse;

/**
 * ClickHouse, которого нет.
 *
 * Отчёты возвращают нули, а не выдуманные обороты: тесты, пользующиеся им,
 * проверяют права, разбор запроса и поведение при отказе — то есть всё, что
 * принадлежит приложению. Достоверность самих цифр принадлежит выгрузке, и
 * подделывать её здесь значило бы проверять собственную подделку.
 */
final class FakeClickHouse extends ClickHouse
{
    /** @var list<array{sql: string, bindings: array<string, mixed>}> */
    public array $queries = [];

    private function __construct(private readonly bool $unavailable) {}

    public static function empty(): self
    {
        return new self(false);
    }

    /** Сервер не отвечает — то, ради чего существует AnalyticsUnavailable. */
    public static function down(): self
    {
        return new self(true);
    }

    /**
     * @param  array<string, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        if ($this->unavailable) {
            throw new AnalyticsUnavailable('Сервер аналитики недоступен.');
        }

        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];

        return [];
    }

    /**
     * Пустая строка вместо ответа: отчёты обязаны пережить период, в котором
     * не было ни одной продажи, — деление на ноль в них не должно случаться.
     *
     * @param  array<string, mixed>  $bindings
     * @return array<string, mixed>
     */
    public function selectOne(string $sql, array $bindings = []): array
    {
        $this->select($sql, $bindings);

        return [];
    }

    /** Значения, ушедшие в запросы, — по ним видно, что склеек не было. */
    public function sql(): string
    {
        return implode("\n", array_column($this->queries, 'sql'));
    }
}