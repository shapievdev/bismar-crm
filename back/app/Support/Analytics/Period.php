<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Отрезок времени, за который смотрят цифры.
 *
 * Живёт отдельным объектом по трём причинам. Во-первых, «за какой период» —
 * единственный вопрос, который задаёт каждая панель, и разбирать его в каждом
 * контроллере заново значит однажды разобрать по-разному. Во-вторых, период
 * сам знает, с каким шагом его рисовать: два дня по месяцам не покажешь, два
 * года по дням — тоже (730 точек на графике шириной в экран). В-третьих, у
 * периода всегда есть предшественник равной длины, и сравнение с ним — то, что
 * превращает «выручка 117 млн» в «выручка 117 млн, +3% к прошлому месяцу».
 */
final readonly class Period
{
    /** Шаг, с которым период разбивается на точки графика. */
    public const GRANULARITY_DAY = 'day';

    public const GRANULARITY_WEEK = 'week';

    public const GRANULARITY_MONTH = 'month';

    /**
     * Пресеты, которые предлагает интерфейс. Значение — сколько дней назад
     * начинается период, считая сегодняшний день первым.
     */
    private const PRESETS = [
        'today' => 1,
        'week' => 7,
        'month' => 30,
        'quarter' => 90,
        'half-year' => 180,
        'year' => 365,
        'two-years' => 730,
    ];

    /**
     * Граница, за которой дневной график перестаёт читаться, и та, за которой
     * перестаёт читаться недельный. Числа подобраны под ширину панели: около
     * сотни столбцов ещё различимы, дальше они сливаются в заливку.
     */
    private const DAYS_BEFORE_WEEKLY = 92;

    private const DAYS_BEFORE_MONTHLY = 400;

    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $granularity,
    ) {}

    /**
     * Период из того, что пришло в запросе.
     *
     * Явные даты сильнее пресета: если клиент прислал обе, пресет игнорируется.
     * Порядок дат не важен — перепутанные местами разворачиваются, потому что
     * это опечатка пользователя, а не повод для ошибки.
     */
    public static function fromRequest(?string $preset, ?string $from, ?string $to, ?string $granularity = null): self
    {
        $period = $from !== null && $to !== null
            ? self::between(CarbonImmutable::parse($from), CarbonImmutable::parse($to))
            : self::preset($preset ?? 'month');

        return $granularity !== null
            ? $period->withGranularity($granularity)
            : $period;
    }

    public static function preset(string $name): self
    {
        $days = self::PRESETS[$name] ?? throw new InvalidArgumentException("Неизвестный период: {$name}");

        $today = CarbonImmutable::today();

        return self::between($today->subDays($days - 1), $today);
    }

    public static function between(CarbonImmutable $from, CarbonImmutable $to): self
    {
        [$from, $to] = $from->lessThanOrEqualTo($to) ? [$from, $to] : [$to, $from];

        $from = $from->startOfDay();
        $to = $to->startOfDay();

        return new self($from, $to, self::granularityFor($from, $to));
    }

    /**
     * Предыдущий отрезок такой же длины — то, с чем сравнивается текущий.
     *
     * Сдвиг ровно на длину периода, а не на календарный месяц: у месяцев разное
     * число дней, и «-8%» получилось бы из одного лишнего вторника.
     */
    public function previous(): self
    {
        $length = $this->days();

        return new self(
            $this->from->subDays($length),
            $this->to->subDays($length),
            $this->granularity,
        );
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function withGranularity(string $granularity): self
    {
        if (! in_array($granularity, self::granularities(), true)) {
            throw new InvalidArgumentException("Неизвестный шаг: {$granularity}");
        }

        return new self($this->from, $this->to, $granularity);
    }

    /**
     * Выражение ClickHouse, сворачивающее дату в точку графика.
     *
     * Возвращается готовым SQL, а не значением параметра: имя функции в
     * плейсхолдер не подставить. Безопасно — выбор идёт из списка констант,
     * а не из того, что прислал клиент; withGranularity() отвергает остальное.
     */
    public function bucketExpression(string $column = 'date'): string
    {
        return match ($this->granularity) {
            self::GRANULARITY_DAY => "toDate({$column})",
            self::GRANULARITY_WEEK => "toMonday({$column})",
            self::GRANULARITY_MONTH => "toStartOfMonth({$column})",
        };
    }

    /**
     * Шаг под длину отрезка: день для квартала, неделя для года, месяц дальше.
     */
    private static function granularityFor(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $days = (int) $from->diffInDays($to) + 1;

        return match (true) {
            $days <= self::DAYS_BEFORE_WEEKLY => self::GRANULARITY_DAY,
            $days <= self::DAYS_BEFORE_MONTHLY => self::GRANULARITY_WEEK,
            default => self::GRANULARITY_MONTH,
        };
    }

    /**
     * @return list<string>
     */
    public static function granularities(): array
    {
        return [self::GRANULARITY_DAY, self::GRANULARITY_WEEK, self::GRANULARITY_MONTH];
    }

    /**
     * @return list<string>
     */
    public static function presets(): array
    {
        return array_keys(self::PRESETS);
    }

    /**
     * Подписи для интерфейса: имя пресета — машинное, человеку нужен смысл.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function presetOptions(): array
    {
        $labels = [
            'today' => 'Сегодня',
            'week' => 'Неделя',
            'month' => 'Месяц',
            'quarter' => 'Квартал',
            'half-year' => 'Полгода',
            'year' => 'Год',
            'two-years' => 'Два года',
        ];

        return array_map(
            static fn (string $value): array => ['value' => $value, 'label' => $labels[$value]],
            self::presets(),
        );
    }

    /**
     * Параметры для запроса. Даты уходят строками — ClickHouse принимает их в
     * сравнении с Date без приведения, а Carbon в плейсхолдер не положишь.
     *
     * @return array{from: string, to: string}
     */
    public function bindings(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
        ];
    }

    /**
     * @return array{from: string, to: string, granularity: string, days: int}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'granularity' => $this->granularity,
            'days' => $this->days(),
        ];
    }

    /** Отпечаток для ключа кэша. */
    public function signature(): string
    {
        return $this->from->toDateString().'..'.$this->to->toDateString().':'.$this->granularity;
    }
}