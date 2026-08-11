<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Support\Analytics\Period;
use App\Support\Analytics\ReportFilters;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Отбор по каналу, складу, менеджеру и сегменту клиента.
 *
 * Главное здесь — что выбранное пользователем значение не попадает в текст
 * запроса. Прав на запись у подключения нет, но чтение чужого — тоже утечка:
 * подставленная строка в условии открыла бы любую таблицу сервера.
 */
final class ReportFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-06 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  list<string>  $warehouses
     * @param  list<string>  $managers
     * @param  list<string>  $segments
     * @param  list<string>  $channels
     */
    private function filters(
        array $warehouses = [],
        array $managers = [],
        array $segments = [],
        array $channels = [],
        bool $withReturns = true,
    ): ReportFilters {
        return new ReportFilters(
            Period::preset('month'),
            $channels,
            $warehouses,
            $managers,
            $segments,
            $withReturns,
        );
    }

    public function test_a_chosen_value_travels_as_a_parameter_and_never_as_text(): void
    {
        $malicious = "База') OR 1=1 --";

        $conditions = $this->filters([$malicious])->conditions();

        $this->assertStringNotContainsString($malicious, $conditions['sql']);
        $this->assertStringContainsString('s.sklad IN (:warehouses)', $conditions['sql']);
        $this->assertSame([$malicious], $conditions['bindings']['warehouses']);
    }

    public function test_every_list_maps_to_its_own_column(): void
    {
        $conditions = $this->filters(['База'], ['Иванов'], ['КОРП'], ['Накладные'])->conditions();

        $this->assertStringContainsString('s.kanal IN (:channels)', $conditions['sql']);
        $this->assertStringContainsString('s.sklad IN (:warehouses)', $conditions['sql']);
        $this->assertStringContainsString('s.menedzher IN (:managers)', $conditions['sql']);
        $this->assertStringContainsString('s.segment_klienta IN (:segments)', $conditions['sql']);
    }

    public function test_an_empty_filter_adds_no_condition_at_all(): void
    {
        // `IN ()` ClickHouse не примет, а условие с пустым списком заставило бы
        // его читать все строки ради заведомо ложного сравнения.
        $conditions = $this->filters()->conditions();

        $this->assertSame('', $conditions['sql']);
        $this->assertSame([], $conditions['bindings']);
    }

    public function test_returns_are_counted_until_they_are_explicitly_dropped(): void
    {
        // Спрятанный возврат показывает продажу, которой не было.
        $this->assertStringNotContainsString('vozvrat', $this->filters()->conditions()['sql']);
        $this->assertStringContainsString('s.vozvrat = 0', $this->filters(withReturns: false)->conditions()['sql']);
    }

    public function test_the_same_choice_in_another_order_is_the_same_question(): void
    {
        // Иначе один и тот же срез считался бы дважды и занимал две записи кэша.
        $this->assertSame(
            $this->filters(['База', 'Ирчи-Казака'])->signature(),
            $this->filters(['Ирчи-Казака', 'База'])->signature(),
        );
    }

    public function test_different_choices_are_different_questions(): void
    {
        $this->assertNotSame(
            $this->filters(['База'])->signature(),
            $this->filters(['Ирчи-Казака'])->signature(),
        );

        $this->assertNotSame(
            $this->filters()->signature(),
            $this->filters(withReturns: false)->signature(),
        );

        // Одно и то же значение в разных списках — разные вопросы.
        $this->assertNotSame(
            $this->filters(warehouses: ['КОРП'])->signature(),
            $this->filters(segments: ['КОРП'])->signature(),
        );
    }

    public function test_the_period_is_part_of_the_signature(): void
    {
        $filters = $this->filters();

        $this->assertNotSame(
            $filters->signature(),
            $filters->withPeriod(Period::preset('year'))->signature(),
        );
    }

    public function test_changing_the_period_keeps_the_rest_of_the_selection(): void
    {
        $filters = $this->filters(['База'], ['Иванов'], ['КОРП'], ['Накладные'], withReturns: false);
        $shifted = $filters->withPeriod(Period::preset('year'));

        $this->assertSame(['База'], $shifted->warehouses);
        $this->assertSame(['Иванов'], $shifted->managers);
        $this->assertSame(['КОРП'], $shifted->segments);
        $this->assertSame(['Накладные'], $shifted->channels);
        $this->assertFalse($shifted->withReturns);
    }
}