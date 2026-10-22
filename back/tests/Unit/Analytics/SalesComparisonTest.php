<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Support\Analytics\ClickHouse;
use App\Support\Analytics\Period;
use App\Support\Analytics\ReportFilters;
use App\Support\Analytics\SalesReport;
use Tests\TestCase;

/**
 * Наложение предыдущего отрезка на текущий.
 *
 * Проверяется ровно то, что ломалось молча: у незакрытого месяца торговых дней
 * меньше, чем у закрытого, и хвост прошлого периода оставался без пары.
 */
final class SalesComparisonTest extends TestCase
{
    /**
     * Ряд обрывается на последнем дне, у которого есть пара.
     *
     * Непарный хвост раньше дорисовывался нулём, и линия текущего периода на
     * последней точке падала в пол — обвал продаж вместо «месяц ещё идёт».
     * Подписи у такой точки не было вовсе: дня, которым она стоит, в текущем
     * периоде не наступило, — а пустую подпись `Intl` на фронте не форматирует,
     * а роняет вместе со страницей.
     */
    public function test_a_day_without_a_pair_does_not_become_a_point(): void
    {
        $report = $this->reportAnswering(
            current: ['2026-10-01' => 100.0, '2026-10-02' => 200.0],
            previous: ['2026-09-01' => 10.0, '2026-09-02' => 20.0, '2026-09-03' => 30.0],
        );

        $points = $report->comparison(new ReportFilters(Period::preset('month')));

        $this->assertCount(2, $points);
        $this->assertSame(
            ['2026-10-01', '2026-10-02'],
            array_column($points, 'label'),
        );
    }

    /** Подпись у каждой точки непустая: по ней фронт строит ось. */
    public function test_every_point_is_labelled(): void
    {
        $report = $this->reportAnswering(
            current: ['2026-10-01' => 100.0],
            previous: ['2026-09-01' => 10.0, '2026-09-02' => 20.0],
        );

        foreach ($report->comparison(new ReportFilters(Period::preset('month'))) as $point) {
            $this->assertNotSame('', $point['label']);
        }
    }

    /** Та же обрезка в обратную сторону: длиннее бывает и текущий отрезок. */
    public function test_the_longer_current_period_is_trimmed_too(): void
    {
        $report = $this->reportAnswering(
            current: ['2026-10-01' => 100.0, '2026-10-02' => 200.0, '2026-10-03' => 300.0],
            previous: ['2026-09-01' => 10.0],
        );

        $points = $report->comparison(new ReportFilters(Period::preset('month')));

        $this->assertCount(1, $points);
        $this->assertSame(10.0, $points[0]['previous']);
    }

    /** Пары сходятся по номеру дня, а не по календарю. */
    public function test_points_pair_up_by_position(): void
    {
        $report = $this->reportAnswering(
            current: ['2026-10-01' => 100.0, '2026-10-02' => 200.0],
            previous: ['2026-09-01' => 10.0, '2026-09-02' => 20.0],
        );

        $points = $report->comparison(new ReportFilters(Period::preset('month')));

        $this->assertSame([100.0, 200.0], array_column($points, 'current'));
        $this->assertSame([10.0, 20.0], array_column($points, 'previous'));
    }

    /**
     * Отчёт, чья витрина отвечает сперва за текущий отрезок, потом за прошлый.
     *
     * @param  array<string, float>  $current
     * @param  array<string, float>  $previous
     */
    private function reportAnswering(array $current, array $previous): SalesReport
    {
        $clickhouse = new class($current, $previous) extends ClickHouse
        {
            /** @var list<list<array<string, mixed>>> */
            private array $answers;

            /**
             * @param  array<string, float>  $current
             * @param  array<string, float>  $previous
             */
            public function __construct(array $current, array $previous)
            {
                $rows = static fn (array $revenueByDate): array => array_map(
                    static fn (string $date, float $revenue): array => ['date' => $date, 'revenue' => $revenue],
                    array_keys($revenueByDate),
                    array_values($revenueByDate),
                );

                $this->answers = [$rows($current), $rows($previous)];
            }

            /**
             * @param  array<string, mixed>  $bindings
             * @return list<array<string, mixed>>
             */
            public function select(string $sql, array $bindings = []): array
            {
                return array_shift($this->answers) ?? [];
            }

            /** Кэш здесь только мешает: ответ заготовлен на один вызов. */
            public function remember(string $key, callable $query): mixed
            {
                return $query();
            }
        };

        return new SalesReport($clickhouse);
    }
}
