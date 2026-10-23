<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Analytics;

use App\Enums\Permission;
use App\Mcp\Support\ReportSlice;
use App\Mcp\Tools\BismarTool;
use App\Support\Analytics\ProductReport;
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
 * Рейтинг: кто и что принесло больше всего.
 *
 * Разрез ограничен перечнем самого отчёта, и это не формальность: имя колонки
 * попадает в SQL склейкой, и неизвестное значение должно упереться в отказ, а не
 * дойти до запроса. На маршруте это сделано `whereIn`, здесь — правилом `in`.
 *
 * Два отчёта в одном инструменте, потому что вопрос один — «разложи выручку», —
 * а разрезы у них разные: у продаж это склад, менеджер, канал, у товаров —
 * бренд, группа, направление.
 */
#[Name('sales_breakdown')]
#[Title('Рейтинг по разрезу')]
#[Description('Раскладывает выручку и прибыль по выбранному разрезу и отдаёт верхние строки: склады, менеджеры, каналы, сегменты клиентов — или товарные разрезы (бренд, группа, направление). Список возможных разрезов приходит в ответе на ошибочный, а также в полях dimensions.')]
final class SalesBreakdown extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ViewAnalytics);
    }

    public function handle(Request $request, SalesReport $sales, ProductReport $products): ResponseFactory|Response
    {
        $this->permit($request, Permission::ViewAnalytics);

        $all = [...SalesReport::dimensions(), ...ProductReport::dimensions()];

        $input = Validator::make($request->all(), [
            ...ReportSlice::rules(),
            'dimension' => ['required', 'string', 'in:'.implode(',', $all)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('analytics.max_top_limit')],
        ], [
            ...ReportSlice::messages(),
            'dimension.in' => 'Неизвестный разрез. Продажи раскладываются по: '
                .implode(', ', SalesReport::dimensions())
                .'. Товары — по: '.implode(', ', ProductReport::dimensions()).'.',
        ])->validate();

        $filters = ReportSlice::from($input);
        $dimension = (string) $input['dimension'];
        $limit = (int) ($input['limit'] ?? config('analytics.top_limit'));

        // Разрез сам говорит, который из двух отчётов умеет его посчитать.
        $report = in_array($dimension, SalesReport::dimensions(), true) ? $sales : $products;

        return $this->attempt(fn (): ResponseFactory => Response::structured([
            'dimension' => $dimension,
            'rows' => $report->breakdown($filters, $dimension, $limit),
            'slice' => $filters->toArray(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...ReportSlice::schema($schema),
            'dimension' => $schema->string()
                ->required()
                ->enum([...SalesReport::dimensions(), ...ProductReport::dimensions()])
                ->description('Разрез, по которому раскладывать выручку.'),
            'limit' => $schema->integer()
                ->min(1)
                ->description('Сколько строк вернуть. По умолчанию столько же, сколько показывает экран.'),
        ];
    }
}
