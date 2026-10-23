<?php

declare(strict_types=1);

namespace App\Data\Analytics;

use App\Support\Analytics\DashboardVocabulary;
use App\Support\Analytics\Period;
use App\Support\Analytics\ReportFilters;

/**
 * Дашборд, собранный моделью, — после проверки по словарю.
 *
 * Между ответом модели и этим объектом стоит `fromModel()`, и это единственная
 * дверь: ни одно слово из ответа не доходит до запроса, не пройдя через словарь
 * (App\Support\Analytics\DashboardVocabulary). Выдуманный показатель, выдуманный
 * разрез, «сто двадцать строк в рейтинге», отрицательная ширина — всё это
 * отсеивается здесь.
 *
 * Негодная панель выбрасывается, а не отменяет весь ответ. Модель на бою —
 * слабая (gpt-4o-mini), и одна панель из шести у неё выйдет неверной
 * непременно; отказ от дашборда целиком означал бы, что сотрудник не видит
 * ничего из-за одной ошибки. Если же не уцелела ни одна — дашборда нет, и
 * остаётся ответ словами: это честнее пустой сетки.
 *
 * Период и отбор собираются теми же классами, что и для вкладок (Period,
 * ReportFilters): ни одно значение отбора не склеивается с SQL, все уходят в
 * запрос параметрами.
 */
final readonly class DashboardSpec
{
    /** Сколько панелей разрешено. Больше восьми — это уже не ответ, а свалка. */
    private const MAX_WIDGETS = 8;

    /** Сетка на двенадцать колонок — та же, что у нынешних вкладок. */
    private const COLUMNS = 12;

    /**
     * @param  list<DashboardWidget>  $widgets
     */
    private function __construct(
        public string $title,
        public ReportFilters $filters,
        public array $widgets,
    ) {}

    /**
     * Разобрать то, что прислала модель.
     *
     * `$refused` заполняется причинами, по которым панели выброшены, и это не
     * отладка. Ответ словами модель пишет **до** того, как мы её проверили, и
     * выброшенная панель оставляет в нём обещание: «по брендам представил
     * рейтинг» — а рейтинга нет. Молча исчезнувшая панель хуже пустой: пустая
     * хотя бы видна. Поэтому причины уезжают обратно сотруднику — дописываются
     * к ответу (см. AnswerWithDashboard).
     *
     * @param  array<string, mixed>  $decoded
     * @param  list<string>  $refused
     */
    public static function fromModel(array $decoded, DashboardVocabulary $vocabulary, array &$refused = []): ?self
    {
        $widgets = self::widgetsFrom($decoded['widgets'] ?? null, $vocabulary, $refused);

        if ($widgets === []) {
            return null;
        }

        return new self(
            title: self::title($decoded['title'] ?? null),
            filters: self::filtersFrom(is_array($decoded['slice'] ?? null) ? $decoded['slice'] : []),
            widgets: $widgets,
        );
    }

    /**
     * Срез — через те же Period и ReportFilters, что у экранов.
     *
     * Значения отбора не сверяются со словарём намеренно, хотя он их знает:
     * несовпавшее даст пустую панель, а не ошибку, и это лучше отказа — склад
     * мог появиться в витрине после того, как словарь собрали. А вот период и
     * шаг графика сверяются: они попадают в выражение запроса.
     *
     * @param  array<string, mixed>  $slice
     */
    private static function filtersFrom(array $slice): ReportFilters
    {
        $preset = self::oneOf($slice['preset'] ?? null, Period::presets());
        $from = self::date($slice['from'] ?? null);
        $to = self::date($slice['to'] ?? null);

        $period = Period::fromRequest(preset: $preset, from: $from, to: $to);
        $step = self::stepFor(self::oneOf($slice['granularity'] ?? null, Period::granularities()), $period);

        return new ReportFilters(
            // Без названного шага период оставляет тот, который выбрал себе
            // сам по длине, — он почти всегда и есть верный.
            period: $step === null ? $period : $period->withGranularity($step),
            channels: self::strings($slice['channels'] ?? null),
            warehouses: self::strings($slice['warehouses'] ?? null),
            managers: self::strings($slice['managers'] ?? null),
            segments: self::strings($slice['segments'] ?? null),
            // Возвраты входят в выручку, пока их явно не исключили: спрятанный
            // возврат показывает продажу, которой не было.
            withReturns: ! array_key_exists('with_returns', $slice) || (bool) $slice['with_returns'],
        );
    }

    /**
     * Шаг графика — названный моделью, если он осмыслен для этой длины.
     *
     * Модель выбирает его плохо: на месячном периоде она просит месячный шаг, а
     * это одна точка — не график, а запятая. Проверяется не вкус, а счёт: шаг,
     * дающий меньше трёх точек, заменяется тем, который период выбирает себе
     * сам по длине (см. Period). Три — не круглое число, а наименьшее, на
     * котором видно направление: две точки показывают только разницу.
     *
     * Названный шаг при этом остаётся главным, когда он годится: «покажи по
     * неделям» должно работать, и подменять его своим расчётом значило бы не
     * услышать просьбу.
     */
    private static function stepFor(?string $wanted, Period $period): ?string
    {
        if ($wanted === null) {
            return null;
        }

        $days = $period->days();

        $enough = match ($wanted) {
            Period::GRANULARITY_MONTH => $days >= 90,
            Period::GRANULARITY_WEEK => $days >= 21,
            default => true,
        };

        return $enough ? $wanted : null;
    }

    /**
     * @param  list<string>  $refused
     * @return list<DashboardWidget>
     */
    private static function widgetsFrom(mixed $raw, DashboardVocabulary $vocabulary, array &$refused = []): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $kinds = $vocabulary->widgets();
        $metrics = $vocabulary->metrics();
        $dimensions = $vocabulary->dimensions();

        $widgets = [];

        foreach ($raw as $row) {
            if (! is_array($row) || count($widgets) >= self::MAX_WIDGETS) {
                continue;
            }

            $type = self::oneOf($row['type'] ?? null, array_keys($kinds));

            if ($type === null) {
                $refused[] = 'такого вида панели у меня нет';

                continue;
            }

            // Показатель приходит то полем, то списком: у графика их два, у
            // остальных один, и модель путает эти два вида записи постоянно.
            $wanted = array_values(array_filter(array_map(
                static fn (mixed $value): ?string => self::oneOf($value, array_keys($metrics)),
                is_array($row['metrics'] ?? null) ? $row['metrics'] : [$row['metric'] ?? null],
            )));

            if ($wanted === []) {
                $refused[] = 'такого показателя аналитика не считает';

                continue;
            }

            $dimension = self::oneOf($row['dimension'] ?? null, array_keys($dimensions));

            // Разрез обязателен там, где без него нечего раскладывать.
            if (in_array('dimension', $kinds[$type]['needs'], true) && $dimension === null) {
                $refused[] = 'такого разреза в аналитике нет';

                continue;
            }

            /*
             * Показатель должен считаться там, куда его просят.
             *
             * «Возвраты по брендам» — любимая просьба модели: по словарю есть и
             * возвраты, и бренды, а отчёты такой строки не считают. Панель
             * выходила пустой, и ответ словами ещё приглашал на неё посмотреть.
             * Словарь теперь говорит, где показатель годится (поле `in`), а
             * здесь это проверяется: полосы, столбцы и кольцо — одно место
             * `ranking`, у них общая раскладка.
             */
            $place = match ($type) {
                'kpi', 'line' => $type,
                default => 'ranking',
            };

            if (! in_array($place, $metrics[$wanted[0]]['in'], true)) {
                $label = $metrics[$wanted[0]]['label'];
                $where = $place === 'ranking' ? 'в раскладке по разрезу' : 'на графике';

                // Без согласования по роду и числу: «возвраты» и «маржа» встают
                // в одну и ту же строку, а подбирать окончания к названиям
                // показателей — работа, которой здесь не место.
                $refused[] = "«{$label}» {$where} не считаются — только итогом за период";

                continue;
            }

            $widgets[] = new DashboardWidget(
                type: $type,
                title: self::title($row['title'] ?? null, $metrics[$wanted[0]]['label']),
                // График рисует две линии, остальные — одну: вторая величина в
                // ширине полосы не выражается.
                metrics: $type === 'line' ? array_slice($wanted, 0, 2) : [$wanted[0]],
                dimension: $dimension,
                limit: self::bounded($row['limit'] ?? null, 3, 25, 10),
                span: self::bounded($row['span'] ?? null, 3, self::COLUMNS, $type === 'kpi' ? 3 : 6),
                rows: self::bounded($row['rows'] ?? null, 1, 3, 1),
                // Сравнение с прошлым периодом умеет только плитка: у
                // остальных панелей рисовать его нечем.
                compare: $type === 'kpi' && (bool) ($row['compare'] ?? true),
            );
        }

        return $widgets;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'slice' => $this->filters->toArray(),
            'widgets' => array_map(static fn (DashboardWidget $widget): array => $widget->toArray(), $this->widgets),
        ];
    }

    private static function title(mixed $value, string $fallback = 'Дашборд'): string
    {
        $title = trim((string) (is_string($value) ? $value : ''));

        return $title === '' ? $fallback : mb_substr($title, 0, 120);
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return in_array($value, $allowed, true) ? $value : null;
    }

    private static function bounded(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1
            ? trim($value)
            : null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $one): string => trim((string) (is_scalar($one) ? $one : '')), $value),
            static fn (string $one): bool => $one !== '',
        ));
    }
}
