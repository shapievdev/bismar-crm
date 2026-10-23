<?php

declare(strict_types=1);

namespace App\Data\Analytics;

/**
 * Одна панель собранного дашборда.
 *
 * Только описание: что показать и чем. Ни цифр, ни запроса — их добавит
 * исполнитель (App\Support\Analytics\DashboardExecutor), а нарисует экран.
 *
 * Ширина и высота заданы в колонках той же сетки из двенадцати, в которой лежат
 * панели нынешних вкладок: размер объявляет сама панель, потому что он
 * относится к содержимому, а не к месту в вёрстке (см. ChartCard).
 */
final readonly class DashboardWidget
{
    /**
     * @param  string  $type  вид панели из словаря: kpi, line, bars, columns, donut
     * @param  list<string>  $metrics  показатели; у плитки и рейтинга один, у графика до двух
     * @param  string|null  $dimension  разрез — у рейтинга, столбцов и кольца
     */
    public function __construct(
        public string $type,
        public string $title,
        public array $metrics,
        public ?string $dimension = null,
        public int $limit = 10,
        public int $span = 6,
        public int $rows = 1,
        public bool $compare = false,
    ) {}

    /**
     * Первый показатель — тот, которым панель мерит.
     *
     * У графика их бывает два, но ширина полосы, высота столбца и доля кольца
     * считаются по одному: второй в них нарисовать нечем.
     */
    public function metric(): string
    {
        return $this->metrics[0] ?? 'revenue';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'title' => $this->title,
            'metrics' => $this->metrics,
            'dimension' => $this->dimension,
            'limit' => $this->limit,
            'span' => $this->span,
            'rows' => $this->rows,
            'compare' => $this->compare,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
