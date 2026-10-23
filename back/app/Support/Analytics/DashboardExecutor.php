<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Data\Analytics\DashboardSpec;
use App\Data\Analytics\DashboardWidget;

/**
 * Считает панели собранного дашборда.
 *
 * Ни одной строки SQL: всё берётся у тех же отчётов, что рисуют вкладки
 * аналитики, — SalesReport, ProductReport. Из этого следует главное свойство
 * ИИ-аналитика: цифра на его панели **та же самая**, что на вкладке продаж за
 * тот же период. Не «посчитанная так же», а буквально та же — вместе с кэшем,
 * так что спрошенное дважды не заставляет ClickHouse читать витрину заново.
 *
 * Итоги спрашиваются один раз на весь дашборд, даже когда плиток четыре: все
 * тринадцать показателей приходят одним запросом (`summary()`), и звать его на
 * каждую плитку значило бы просканировать витрину четыре раза ради одной
 * картинки.
 *
 * Отдаёт сразу в том виде, в каком ждут компоненты экрана: плитка — число и
 * прошлое, график — точки и серии, рейтинг — имя со значением. Пересобирать это
 * на фронте было бы вторым местом, где знают, что такое «маржа», — и однажды
 * эти два места разошлись бы.
 */
final readonly class DashboardExecutor
{
    public function __construct(
        private SalesReport $sales,
        private ProductReport $products,
        private DashboardVocabulary $vocabulary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function render(DashboardSpec $spec): array
    {
        $metrics = $this->vocabulary->metrics();

        // Итоги нужны плиткам, и только им. Нет плиток — нет и запроса.
        $summary = $this->needsSummary($spec) ? $this->sales->summary($spec->filters) : null;

        $panels = [];

        foreach ($spec->widgets as $widget) {
            $panel = match ($widget->type) {
                'kpi' => $this->kpi($widget, $summary ?? [], $metrics),
                'line' => $this->line($widget, $spec, $metrics),
                default => $this->ranking($widget, $spec, $metrics),
            };

            if ($panel !== null) {
                $panels[] = $panel;
            }
        }

        return [
            'title' => $spec->title,
            'slice' => $spec->filters->toArray(),
            'panels' => $panels,
        ];
    }

    /**
     * Плитка: число за период и то же за предыдущий.
     *
     * @param  array{current?: array<string, float|int>, previous?: array<string, float|int>}  $summary
     * @param  array<string, array{label: string, format: string, growth: string, hint?: string}>  $metrics
     * @return array<string, mixed>
     */
    private function kpi(DashboardWidget $widget, array $summary, array $metrics): array
    {
        $metric = $widget->metric();
        $meta = $metrics[$metric];

        return array_filter([
            'type' => 'kpi',
            'span' => $widget->span,
            'label' => $widget->title,
            'value' => (float) ($summary['current'][$metric] ?? 0),
            'previous' => $widget->compare ? (float) ($summary['previous'][$metric] ?? 0) : null,
            'format' => $meta['format'],
            'growth' => $meta['growth'],
            'hint' => $meta['hint'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * График: точки во времени и одна-две серии.
     *
     * Шаг графика спрашивается у самого периода, а не у панели: он уже выбран
     * — либо моделью, либо по длине периода, — и второе мнение о нём рассыпало
     * бы подписи на оси.
     *
     * @param  array<string, array{label: string, format: string, growth: string, hint?: string}>  $metrics
     * @return array<string, mixed>|null
     */
    private function line(DashboardWidget $widget, DashboardSpec $spec, array $metrics): ?array
    {
        $points = $this->sales->trend($spec->filters);

        // Из trend() приходят не все показатели — он отдаёт четыре. Просить у
        // него маржу значило бы показать пустую линию: лучше не рисовать.
        $available = array_values(array_filter(
            $widget->metrics,
            static fn (string $metric): bool => in_array($metric, ['revenue', 'profit', 'orders', 'average_order'], true),
        ));

        if ($available === []) {
            return null;
        }

        return [
            'type' => 'line',
            'span' => $widget->span,
            'rows' => max($widget->rows, 2),
            'title' => $widget->title,
            'granularity' => $spec->filters->period->granularity,
            'points' => $points,
            'series' => array_map(static fn (string $metric, int $index): array => [
                'key' => $metric,
                'label' => $metrics[$metric]['label'],
                // Первая серия — сплошной линией по штриховке, вторая — тонкой:
                // различает их фактура, а не цвет (см. TrendChart).
                'slot' => $index === 0 ? 1 : 2,
                'format' => $metrics[$metric]['format'] === 'money' ? 'money' : 'number',
            ], $available, array_keys($available)),
        ];
    }

    /**
     * Рейтинг, столбцы или кольцо — три вида одной раскладки по разрезу.
     *
     * @param  array<string, array{label: string, format: string, growth: string, hint?: string}>  $metrics
     * @return array<string, mixed>|null
     */
    private function ranking(DashboardWidget $widget, DashboardSpec $spec, array $metrics): ?array
    {
        $dimension = (string) $widget->dimension;
        $metric = $widget->metric();
        $rows = $this->rowsFor($dimension, $spec, $widget->limit);

        /*
         * Пустая раскладка панель не отменяет, хотя соблазн велик. Ответ
         * словами обещает «магазины — в рейтинг», и исчезнувший рейтинг
         * расходится с обещанием: сотрудник решит, что приложение сломалось.
         * Пустая панель говорит правду — за этот срез нет продаж, — и говорит
         * её на своём месте.
         */
        $format = $metrics[$metric]['format'] === 'money' ? 'money' : 'number';

        return array_filter([
            'type' => $widget->type,
            'span' => $widget->span,
            'rows' => $widget->rows,
            'title' => $widget->title,
            'format' => $format,
            'items' => array_values(array_filter(array_map(
                fn (array $row): ?array => $this->item($row, $metric, $metrics),
                $rows,
            ))),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Строка раскладки — имя, величина и вторая величина подписью.
     *
     * `uncertain` — та самая оговорка о марже: доля выручки, для которой витрина
     * не знает себестоимости. Полоса такую строку приглушает, и это
     * единственный способ показать «маржа тут приблизительная», не уводя
     * человека в сноски.
     *
     * @param  array<string, float|int|string>  $row
     * @param  array<string, array{label: string, format: string, growth: string, hint?: string}>  $metrics
     * @return array<string, mixed>|null
     */
    private function item(array $row, string $metric, array $metrics): ?array
    {
        if (! array_key_exists($metric, $row)) {
            return null;
        }

        $uncertain = isset($row['without_cost_share'])
            && in_array($metric, ['margin', 'profit'], true)
            && (float) $row['without_cost_share'] > DashboardVocabulary::UNRELIABLE_MARGIN_SHARE;

        return array_filter([
            'name' => (string) $row['name'],
            'value' => (float) $row[$metric],
            // Подпись: маржа рядом с выручкой, число документов рядом с маржой —
            // то, чего нет в самой длине полосы.
            'meta' => $this->meta($row, $metric),
            'uncertain' => $uncertain ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, float|int|string>  $row
     */
    private function meta(array $row, string $metric): ?string
    {
        if ($metric !== 'revenue' && $metric !== 'profit') {
            return null;
        }

        return isset($row['margin']) ? 'маржа '.$row['margin'].'%' : null;
    }

    /**
     * Откуда берётся раскладка по этому разрезу.
     *
     * Два разреза считаются не рейтингом, а своими панелями отчёта, и словарь
     * о них знает: каналов несколько и порядок у них постоянный, а дней недели
     * ровно семь и сортировать их по выручке бессмысленно. Имена полей у них
     * свои, поэтому приводятся к общему виду здесь.
     *
     * @return list<array<string, float|int|string>>
     */
    private function rowsFor(string $dimension, DashboardSpec $spec, int $limit): array
    {
        $report = $this->vocabulary->dimensions()[$dimension]['report'] ?? 'sales';

        return match ($report) {
            'channels' => array_map(
                static fn (array $row): array => ['name' => $row['channel'], ...$row],
                $this->sales->channels($spec->filters),
            ),
            'weekday' => array_map(
                static fn (array $row): array => ['name' => $row['label'], 'revenue' => $row['revenue'], 'orders' => $row['orders']],
                $this->sales->weekdayProfile($spec->filters),
            ),
            'products' => $this->products->breakdown($spec->filters, $dimension, $limit),
            default => $this->sales->breakdown($spec->filters, $dimension, $limit),
        };
    }

    private function needsSummary(DashboardSpec $spec): bool
    {
        foreach ($spec->widgets as $widget) {
            if ($widget->type === 'kpi') {
                return true;
            }
        }

        return false;
    }
}
