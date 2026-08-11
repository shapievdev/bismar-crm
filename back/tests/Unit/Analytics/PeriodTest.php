<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Support\Analytics\Period;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Отрезок, за который смотрят цифры.
 *
 * Проверяется то, что молча портит все панели разом: смещение сравнения,
 * неверный шаг графика и попытка провести имя функции в SQL через параметр
 * шага.
 */
final class PeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Пресеты считаются от «сегодня», поэтому день фиксируется — иначе тест
        // ведёт себя по-разному в зависимости от того, когда его запустили.
        CarbonImmutable::setTestNow('2026-08-06 15:30:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_preset_counts_today_as_its_last_day(): void
    {
        $period = Period::preset('week');

        $this->assertSame('2026-07-31', $period->toArray()['from']);
        $this->assertSame('2026-08-06', $period->toArray()['to']);
        $this->assertSame(7, $period->days());
    }

    public function test_the_previous_period_is_shifted_by_its_own_length(): void
    {
        // Сдвиг на длину, а не на календарный месяц: у месяцев разное число
        // дней, и сравнение поехало бы на один лишний рабочий день.
        $previous = Period::preset('week')->previous();

        $this->assertSame('2026-07-24', $previous->toArray()['from']);
        $this->assertSame('2026-07-30', $previous->toArray()['to']);
        $this->assertSame(7, $previous->days());
    }

    public function test_the_two_periods_never_overlap(): void
    {
        $period = Period::preset('month');
        $previous = $period->previous();

        $this->assertTrue(
            CarbonImmutable::parse($previous->toArray()['to'])
                ->lessThan(CarbonImmutable::parse($period->toArray()['from'])),
            'Предыдущий период не должен захватывать ни одного дня текущего.',
        );
    }

    public function test_the_step_follows_the_length_of_the_period(): void
    {
        $this->assertSame('day', Period::preset('quarter')->toArray()['granularity']);
        $this->assertSame('week', Period::preset('year')->toArray()['granularity']);
        $this->assertSame('month', Period::preset('two-years')->toArray()['granularity']);
    }

    public function test_dates_given_the_wrong_way_round_are_swapped(): void
    {
        $period = Period::between(
            CarbonImmutable::parse('2026-08-06'),
            CarbonImmutable::parse('2026-08-01'),
        );

        $this->assertSame('2026-08-01', $period->toArray()['from']);
        $this->assertSame('2026-08-06', $period->toArray()['to']);
    }

    public function test_explicit_dates_win_over_a_preset(): void
    {
        $period = Period::fromRequest('year', '2026-08-01', '2026-08-05');

        $this->assertSame('2026-08-01', $period->toArray()['from']);
        $this->assertSame('2026-08-05', $period->toArray()['to']);
    }

    public function test_the_bucket_expression_is_built_only_from_known_steps(): void
    {
        $this->assertSame('toDate(s.date)', Period::preset('week')->bucketExpression('s.date'));
        $this->assertSame(
            'toStartOfMonth(s.date)',
            Period::preset('week')->withGranularity('month')->bucketExpression('s.date'),
        );
    }

    public function test_an_unknown_step_is_refused_rather_than_reaching_the_query(): void
    {
        // Шаг попадает в SQL склейкой, поэтому список закрыт: принять его от
        // клиента как есть означало бы отдать серверу произвольное выражение.
        $this->expectException(InvalidArgumentException::class);

        Period::preset('week')->withGranularity("toDate(x)) FROM system.tables --");
    }

    public function test_an_unknown_preset_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Period::preset('всё время');
    }
}