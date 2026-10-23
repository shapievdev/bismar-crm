<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * Срез, в котором смотрят цифры: период плюс отбор по каналу, складу,
 * менеджеру и сегменту клиента.
 *
 * Фильтры собраны в один объект, потому что ходят вместе: каждая панель
 * принимает их целиком и целиком же кладёт в ключ кэша. Разложенные по
 * аргументам, они превратили бы каждый метод отчёта в шесть параметров, из
 * которых половина всегда пуста.
 *
 * Значения отбора приходят от пользователя и уходят в запрос **только
 * параметрами** — ни одно из них не склеивается с SQL. Прав на запись у
 * подключения нет, но чтение чужого тоже утечка: подставленная строка в
 * условии открыла бы любую таблицу сервера.
 */
final readonly class ReportFilters
{
    /**
     * @param  list<string>  $channels  Накладные, чеки ККМ — как их зовёт витрина
     * @param  list<string>  $warehouses
     * @param  list<string>  $managers
     * @param  list<string>  $segments  сегмент клиента: КОРП, Розница, Тендер…
     * @param  list<string>  $employees  имена продавцов, как их пишет 1С
     */
    public function __construct(
        public Period $period,
        public array $channels = [],
        public array $warehouses = [],
        public array $managers = [],
        public array $segments = [],
        /**
         * Считать ли возвраты. По умолчанию да: возврат уменьшает выручку, и
         * спрятать его значит показать продажи, которых не было. Снимается,
         * когда нужно увидеть отгрузку саму по себе.
         */
        public bool $withReturns = true,
        /**
         * Продавцы — именами из витрины, а не сотрудниками платформы.
         *
         * Нужен этот отбор одному спрашивающему — ИИ-аналитику, и приходит он
         * к нему не от человека: человек спрашивает «продажи оптового отдела»,
         * а в имена продавцов это разворачивает структура компании (см.
         * App\Support\Analytics\CompanyStructure). Экранам вкладок он не
         * нужен — у них для людей своя вкладка.
         *
         * Стоит последним намеренно: отбор добавлен позже остальных, а
         * вставленный в середину он переломал бы всех, кто собирает срез
         * позиционно, — и ради стройности списка.
         */
        public array $employees = [],
    ) {}

    public function withPeriod(Period $period): self
    {
        return new self(
            $period,
            $this->channels,
            $this->warehouses,
            $this->managers,
            $this->segments,
            $this->withReturns,
            $this->employees,
        );
    }

    /**
     * Условия отбора и параметры к ним.
     *
     * Пустой фильтр не добавляет условия вовсе — `IN ()` ClickHouse не примет,
     * а условие с пустым списком заставило бы его читать все строки ради
     * заведомо ложного сравнения.
     *
     * @return array{sql: string, bindings: array<string, mixed>}
     */
    public function conditions(): array
    {
        $sql = '';
        $bindings = [];

        foreach ($this->lists() as $parameter => [$column, $values]) {
            if ($values !== []) {
                $sql .= " AND s.{$column} IN (:{$parameter})";
                $bindings[$parameter] = $values;
            }
        }

        if (! $this->withReturns) {
            $sql .= ' AND s.vozvrat = 0';
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /**
     * Колонка витрины, отвечающая за каждый список.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    private function lists(): array
    {
        return [
            'channels' => ['kanal', $this->channels],
            'warehouses' => ['sklad', $this->warehouses],
            'managers' => ['menedzher', $this->managers],
            'segments' => ['segment_klienta', $this->segments],
            // Тот же столбец, по которому ведётся сопоставление продавцов с
            // отделами, — см. EmployeeReport.
            'employees' => ['sotrudnik', $this->employees],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period->toArray(),
            'channels' => $this->channels,
            'warehouses' => $this->warehouses,
            'managers' => $this->managers,
            'segments' => $this->segments,
            'employees' => $this->employees,
            'with_returns' => $this->withReturns,
        ];
    }

    /**
     * Отпечаток среза для ключа кэша.
     *
     * Списки сортируются: те же два склада, выбранные в другом порядке, — тот
     * же вопрос, и второй раз считать его незачем.
     */
    public function signature(): string
    {
        $parts = [$this->period->signature()];

        foreach ($this->lists() as [, $values]) {
            sort($values);
            $parts[] = implode(',', $values);
        }

        $parts[] = $this->withReturns ? 'returns' : 'no-returns';

        return implode('|', $parts);
    }
}
