<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Support\Analytics\Period;
use App\Support\Analytics\ReportFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Срез, за который спрашивают цифры.
 *
 * Один запрос на все панели: вопрос «за какой период и по каким складам» у них
 * общий, и разные правила для одного и того же параметра — это способ однажды
 * разойтись. Право проверяется на маршруте, поэтому здесь только разбор.
 *
 * Списки отбора не сверяются со справочником намеренно. Значение приходит в
 * запрос параметром и в худшем случае не совпадёт ни с одной строкой — ответом
 * будет пустая панель, а не ошибка. Проверять каждое против витрины значило бы
 * лишний поход в ClickHouse на каждый запрос ради того же результата.
 */
final class ReportRequest extends FormRequest
{
    /** Отборы, которые приходят списками. */
    private const LISTS = ['channels', 'warehouses', 'managers', 'segments'];

    /**
     * Одиночное значение отбора — тоже список из одного элемента.
     *
     * `?warehouses=Основной` и `?warehouses[]=Основной` спрашивают об одном и
     * том же, и отвечать на первое отказом значило бы требовать от клиента
     * знания того, как PHP разбирает строку запроса. Правило `array` ниже
     * остаётся: оно ловит уже настоящую ошибку — объект вместо списка.
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (self::LISTS as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalised[$key] = [$value];
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preset' => ['sometimes', 'string', Rule::in(Period::presets())],
            'from' => ['sometimes', 'date', 'required_with:to'],
            'to' => ['sometimes', 'date', 'required_with:from', 'after_or_equal:from'],
            'granularity' => ['sometimes', 'string', Rule::in(Period::granularities())],

            'channels' => ['sometimes', 'array', 'max:10'],
            'channels.*' => ['string', 'max:255'],

            'warehouses' => ['sometimes', 'array', 'max:50'],
            'warehouses.*' => ['string', 'max:255'],

            'managers' => ['sometimes', 'array', 'max:50'],
            'managers.*' => ['string', 'max:255'],

            'segments' => ['sometimes', 'array', 'max:50'],
            'segments.*' => ['string', 'max:255'],

            'with_returns' => ['sometimes', 'boolean'],

            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.config('analytics.max_top_limit')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'Конец периода не может быть раньше его начала.',
            'preset.in' => 'Неизвестный период.',
            'granularity.in' => 'Неизвестный шаг графика.',
            // Отказ виден человеку целиком — панель показывает его как есть, —
            // и англоязычная формулировка фреймворка в русском интерфейсе
            // выглядит поломкой, а не объяснением.
            'channels.array' => 'Каналы передаются списком.',
            'warehouses.array' => 'Склады передаются списком.',
            'managers.array' => 'Менеджеры передаются списком.',
            'segments.array' => 'Сегменты передаются списком.',
            'limit.max' => 'Строк в рейтинге запрошено больше, чем отдаёт отчёт.',
        ];
    }

    public function filters(): ReportFilters
    {
        return new ReportFilters(
            period: $this->period(),
            channels: $this->strings('channels'),
            warehouses: $this->strings('warehouses'),
            managers: $this->strings('managers'),
            segments: $this->strings('segments'),
            // Возвраты входят в выручку, пока их явно не исключили: спрятанный
            // возврат показывает продажу, которой не было.
            withReturns: ! $this->has('with_returns') || $this->boolean('with_returns'),
        );
    }

    public function period(): Period
    {
        return Period::fromRequest(
            preset: $this->string('preset')->isNotEmpty() ? $this->string('preset')->value() : null,
            from: $this->date('from')?->toDateString(),
            to: $this->date('to')?->toDateString(),
            granularity: $this->string('granularity')->isNotEmpty() ? $this->string('granularity')->value() : null,
        );
    }

    public function limit(): int
    {
        return $this->integer('limit', (int) config('analytics.top_limit'));
    }

    /**
     * @return list<string>
     */
    private function strings(string $key): array
    {
        /** @var list<mixed> $values */
        $values = $this->input($key, []);

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        ));
    }
}
