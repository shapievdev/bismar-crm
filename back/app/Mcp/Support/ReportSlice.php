<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Support\Analytics\Period;
use App\Support\Analytics\ReportFilters;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Срез, за который спрашивают цифры, — собранный из доводов инструмента.
 *
 * То же, что делает ReportRequest для экрана, только вход другой: у коннектора
 * нет HTTP-запроса, из которого можно собрать FormRequest. Правила и сборка
 * фильтров поэтому здесь, а периоды и условия отбора — по-прежнему в Period и
 * ReportFilters: ни одного значения этот класс в SQL не кладёт.
 *
 * Общий на все отчёты, потому что вопрос «за какой период и по каким складам» у
 * них один. Разойдись он по четырём инструментам — и «за полгода» однажды стало
 * бы значить в продажах одно, а в товарах другое.
 */
final class ReportSlice
{
    /**
     * Правила — те же, что в ReportRequest.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'preset' => ['nullable', 'string', 'in:'.implode(',', Period::presets())],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
            'granularity' => ['nullable', 'string', 'in:'.implode(',', Period::granularities())],

            'channels' => ['nullable', 'array', 'max:10'],
            'channels.*' => ['string', 'max:255'],
            'warehouses' => ['nullable', 'array', 'max:50'],
            'warehouses.*' => ['string', 'max:255'],
            'managers' => ['nullable', 'array', 'max:50'],
            'managers.*' => ['string', 'max:255'],
            'segments' => ['nullable', 'array', 'max:50'],
            'segments.*' => ['string', 'max:255'],

            'with_returns' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'to.after_or_equal' => 'Конец периода не может быть раньше его начала.',
            'preset.in' => 'Неизвестный период. Возможные: '.implode(', ', Period::presets()).'.',
            'granularity.in' => 'Неизвестный шаг графика: бывает day, week или month.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input  уже проверенное
     */
    public static function from(array $input): ReportFilters
    {
        return new ReportFilters(
            period: Period::fromRequest(
                preset: self::string($input, 'preset'),
                from: self::string($input, 'from'),
                to: self::string($input, 'to'),
                granularity: self::string($input, 'granularity'),
            ),
            channels: self::strings($input, 'channels'),
            warehouses: self::strings($input, 'warehouses'),
            managers: self::strings($input, 'managers'),
            segments: self::strings($input, 'segments'),
            // Возвраты входят в выручку, пока их явно не исключили: спрятанный
            // возврат показывает продажу, которой не было.
            withReturns: ! array_key_exists('with_returns', $input) || (bool) $input['with_returns'],
        );
    }

    /**
     * Описание доводов среза — одно на все отчёты.
     *
     * @return array<string, mixed>
     */
    public static function schema(JsonSchema $schema): array
    {
        return [
            'preset' => $schema->string()
                ->enum(Period::presets())
                ->description('Готовый период: today, week, month, quarter, half-year, year, two-years. По умолчанию month.'),
            'from' => $schema->string()->description('Начало произвольного периода, ГГГГ-ММ-ДД. Вместе с to и вместо preset.'),
            'to' => $schema->string()->description('Конец произвольного периода, ГГГГ-ММ-ДД.'),
            'granularity' => $schema->string()
                ->enum(Period::granularities())
                ->description('Шаг графика: day, week или month. По умолчанию выбирается по длине периода.'),
            'channels' => $schema->array()->items($schema->string())
                ->description('Каналы продаж — значения из analytics_directory.'),
            'warehouses' => $schema->array()->items($schema->string())
                ->description('Склады и магазины — значения из analytics_directory.'),
            'managers' => $schema->array()->items($schema->string())
                ->description('Менеджеры — значения из analytics_directory. Это имена из витрины, а не сотрудники платформы.'),
            'segments' => $schema->array()->items($schema->string())
                ->description('Сегменты клиентов — значения из analytics_directory.'),
            'with_returns' => $schema->boolean()
                ->description('Считать ли возвраты. По умолчанию да: возврат уменьшает выручку, и спрятать его значит показать продажи, которых не было.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function string(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private static function strings(array $input, string $key): array
    {
        $values = $input[$key] ?? [];

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        ));
    }
}
