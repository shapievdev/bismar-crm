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
        private EmployeeReport $employees,
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
            'caveats' => $this->caveats($spec, $summary),
        ];
    }

    /**
     * Оговорки об **этих** цифрах — от того, кто их посчитал.
     *
     * Раньше о них просили модель, и она пересказывала их наизусть в каждом
     * ответе: «если доля выручки без себестоимости выше 20%, маржа
     * приблизительна». Беда не в многословии, а в том, что настоящей доли она
     * не знает — её знает сервер. Поэтому оговорка теперь говорится только
     * тогда, когда она верна, и с настоящим числом.
     *
     * @param  array{current?: array<string, float|int>}|null  $summary
     * @return list<string>
     */
    private function caveats(DashboardSpec $spec, ?array $summary): array
    {
        $caveats = [];

        // Итоги уже посчитаны, если на дашборде есть плитки; иначе спрашиваем —
        // запрос кэширован и общий с вкладками.
        $share = (float) (($summary ?? $this->sales->summary($spec->filters))['current']['without_cost_share'] ?? 0);

        if ($share > DashboardVocabulary::UNRELIABLE_MARGIN_SHARE) {
            $caveats[] = 'Маржа и прибыль здесь приблизительны: у '.$this->percent($share)
                .' выручки в этом срезе витрина не знает себестоимости.';
        }

        $unloaded = $this->unloadedDays($spec);

        if ($unloaded !== null) {
            $caveats[] = $unloaded;
        }

        return $caveats;
    }

    /**
     * Захватывает ли срез дни, которых в витрине ещё нет.
     *
     * Выгрузка приходит ночью, и в течение дня самая свежая продажа —
     * вчерашняя. Без этой оговорки «ноль за сегодня» читается как провал в
     * торговле, а незаконченный месяц — как падение выручки.
     */
    private function unloadedDays(DashboardSpec $spec): ?string
    {
        $last = $this->vocabulary->lastLoadedDate();

        if ($last === null || $spec->filters->period->to->toDateString() <= $last) {
            return null;
        }

        return 'Данные доехали по '.$this->day($last).', а срез заканчивается '
            .$this->day($spec->filters->period->to->toDateString())
            .' — последние дни в нём неполные.';
    }

    private function percent(float $share): string
    {
        return rtrim(rtrim(number_format($share, 1, ',', ' '), '0'), ',').'%';
    }

    private function day(string $date): string
    {
        return implode('.', array_reverse(explode('-', $date)));
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

        /*
         * График из одной-двух точек — не график, а запятая. Шаг мы уже
         * поправили там, где он был заведомо крупен для периода (см.
         * DashboardSpec::stepFor), но остаётся случай, которого тем правилом
         * не покрыть: «продажи вчера» — это один день, и дневной шаг даёт на
         * нём ровно одну точку. Панели в таком ответе просто не место: плитки
         * рядом говорят то же самое и говорят честнее.
         */
        if (count($points) < 3) {
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
         *
         * А вот раскладка, в которой строки есть, но запрошенного показателя в
         * них нет, — другое дело, и панель здесь исчезает. Это не «нет
         * продаж», а «так не считается»: разрез вроде дня недели знает только
         * выручку и документы. Словарь такие просьбы уже отсекает (DashboardSpec),
         * но у двух разрезов — дней недели и каналов — свои наборы полей, и
         * выразить это в словаре значило бы расписать его по каждому разрезу.
         */
        if ($rows !== [] && ! array_key_exists($metric, $rows[0])) {
            return null;
        }

        $format = $metrics[$metric]['format'] === 'money' ? 'money' : 'number';

        $items = array_values(array_filter(array_map(
            fn (array $row): ?array => $this->item($row, $metric, $metrics),
            $rows,
        )));

        return array_filter([
            'type' => $widget->type,
            'span' => $widget->span,
            'rows' => $this->rowsFitting($widget, count($items)),
            'title' => $widget->title,
            'format' => $format,
            'items' => $items,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Сколько рядов сетки занять раскладке — по числу строк, а не по мнению
     * модели.
     *
     * Высоту она ставит наугад и чаще всего не ставит вовсе, а умолчание —
     * один ряд: рейтинг на десять строк оказывался в карточке высотой в три,
     * и человек видел одну строку с полосой прокрутки. Считать высоту должен
     * тот, кто знает число строк, то есть это место.
     *
     * Названное моделью остаётся нижней границей: попросила выше — пусть будет
     * выше, это её право. А три ряда — потолок: панель во весь экран вытесняет
     * собой всё, ради чего рядом стоят остальные.
     */
    private function rowsFitting(DashboardWidget $widget, int $items): int
    {
        // Кольцо мерится не строками, а своим диаметром: долей в нём от двух до
        // шести, и больше двух рядов ему ни к чему.
        $needed = $widget->type === 'donut'
            ? 2
            : (int) ceil($items / 4);

        return max(2, min(3, max($widget->rows, $needed)));
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
            // Сотрудники и отделы — не из витрины, а из структуры компании: она
            // знает продавца строкой из 1С, а человека и отдел за этой строкой
            // знаем мы (см. CompanyStructure).
            'employees' => $this->byEmployee($spec, $limit),
            'departments' => $this->byDepartment($spec, $limit),
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

    /**
     * Рейтинг сотрудников — именами платформы.
     *
     * Строка из 1С остаётся там, где сопоставления нет: так в отчёте видно и
     * того, кто есть в карточках, и того, кого забыли сопоставить.
     *
     * @return list<array<string, float|int|string>>
     */
    private function byEmployee(DashboardSpec $spec, int $limit): array
    {
        $structure = $this->vocabulary->structure();

        $rows = array_map(
            static fn (array $row): array => [
                ...$row,
                'name' => $structure->nameFor((string) $row['name']),
            ],
            $this->employees->ranking($spec->filters, $limit),
        );

        return $rows;
    }

    /**
     * Рейтинг отделов — свёрткой продавцов по структуре.
     *
     * Соединить это запросом нельзя: витрина и структура компании стоят на
     * разных серверах, и витрина об отделах не знает. Поэтому сумма считается
     * здесь, по тому же сопоставлению, на котором стоит отчёт план-факт.
     *
     * Свёртка плоская, без переноса на вышестоящие отделы: иначе родитель и
     * ребёнок стояли бы в одном рейтинге, и выручка считалась бы дважды. Чтобы
     * спросить отдел вместе с подчинёнными, его называют отбором — там ветка
     * разворачивается целиком.
     *
     * @return list<array<string, float|int|string>>
     */
    private function byDepartment(DashboardSpec $spec, int $limit): array
    {
        $structure = $this->vocabulary->structure();

        $totals = [];

        foreach ($this->employees->everyone($spec->filters) as $row) {
            // Несопоставленные собираются в одну строку: «кто это» — вопрос к
            // экрану отделов продаж, а не к отчёту, но молча потерять их
            // выручку нельзя.
            $name = $structure->departmentOf((string) $row['name']) ?? 'Не сопоставлены с отделом';

            $totals[$name] ??= ['name' => $name, 'revenue' => 0.0, 'profit' => 0.0, 'orders' => 0];
            $totals[$name]['revenue'] += (float) $row['revenue'];
            $totals[$name]['profit'] += (float) $row['profit'];
            $totals[$name]['orders'] += (int) $row['orders'];
        }

        $rows = array_values($totals);

        usort($rows, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return array_map(static fn (array $row): array => [
            ...$row,
            'margin' => $row['revenue'] > 0 ? round($row['profit'] / $row['revenue'] * 100, 1) : 0.0,
        ], array_slice($rows, 0, $limit));
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
