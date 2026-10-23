<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Analytics;

use App\Enums\Permission;
use App\Mcp\Support\ReportSlice;
use App\Mcp\Tools\BismarTool;
use App\Support\Analytics\SalesReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Продажи: сколько наторговали и на чём заработали.
 *
 * Экран отдаёт семь панелей одним ответом — ему так и надо, он их все рисует.
 * Модели нужно другое: итоги и сравнение с прошлым периодом почти всегда, а
 * график по дням и профиль по дням недели — изредка и по делу. Поэтому разделы
 * выбираются доводом: год с шагом в день — это триста шестьдесят пять точек, и
 * присылать их за компанию с итогами значило бы тратить разговор на числа,
 * которых не просили.
 *
 * Считает всё это SalesReport, и кэш у него общий с экраном: тот же срез,
 * спрошенный коннектором, не заставляет ClickHouse сканировать витрину заново.
 */
#[Name('sales_report')]
#[Title('Отчёт о продажах')]
#[Description('Выручка, прибыль, маржа, возвраты, число заказов и средний чек за период — и то же самое за предыдущий период для сравнения. По запросу добавляет график по дням (trend), разбивку по каналам продаж (channels), сравнение с прошлым периодом по точкам (comparison), профиль по дням недели (weekday) и структуру себестоимости (cost_structure). Отбор по складам, каналам, менеджерам и сегментам — значениями из analytics_directory.')]
final class SalesReportTool extends BismarTool
{
    /**
     * Что умеет отдать отчёт. Итоги идут всегда — без них ответ не ответ.
     */
    private const SECTIONS = ['trend', 'channels', 'channel_trend', 'comparison', 'weekday', 'cost_structure'];

    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ViewAnalytics);
    }

    public function handle(Request $request, SalesReport $report): ResponseFactory|Response
    {
        $this->permit($request, Permission::ViewAnalytics);

        $input = Validator::make($request->all(), [
            ...ReportSlice::rules(),
            'sections' => ['nullable', 'array'],
            'sections.*' => ['string', 'in:'.implode(',', self::SECTIONS)],
        ], ReportSlice::messages())->validate();

        $filters = ReportSlice::from($input);

        /** @var list<string> $sections */
        $sections = $input['sections'] ?? ['comparison'];

        return $this->attempt(function () use ($filters, $report, $sections): ResponseFactory {
            $data = ['summary' => $report->summary($filters)];

            foreach (array_unique($sections) as $section) {
                $data[$section] = match ($section) {
                    'trend' => $report->trend($filters),
                    'channels' => $report->channels($filters),
                    'channel_trend' => $report->channelTrend($filters),
                    'comparison' => $report->comparison($filters),
                    'weekday' => $report->weekdayProfile($filters),
                    default => $report->costStructure($filters),
                };
            }

            return Response::structured([
                ...$data,
                'slice' => $filters->toArray(),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...ReportSlice::schema($schema),
            'sections' => $schema->array()
                ->items($schema->string()->enum(self::SECTIONS))
                ->description('Какие разделы добавить к итогам: trend — график, channels — каналы продаж, channel_trend — каналы по точкам графика, comparison — сравнение с прошлым периодом, weekday — дни недели, cost_structure — выручка, себестоимость и прибыль. По умолчанию comparison.'),
        ];
    }
}
