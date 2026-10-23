<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Analytics;

use App\Enums\Permission;
use App\Mcp\Support\ReportSlice;
use App\Mcp\Tools\BismarTool;
use App\Support\Analytics\ProductReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Товары: что продаётся и что лежит.
 *
 * Две панели и два разных вопроса. Матрица говорит, чем компания живёт, —
 * выручка и маржа по направлениям; неликвид говорит, где заморожены деньги.
 * Рейтинг по бренду, группе или товару спрашивается отдельно — sales_breakdown
 * с товарным разрезом.
 */
#[Name('product_report')]
#[Title('Отчёт по товарам')]
#[Description('Товарная картина за период: матрица выручки и маржи по направлениям и группам и перечень неликвида — того, что лежит без движения. Рейтинг конкретных товаров, брендов или групп спрашивается инструментом sales_breakdown с разрезом item, brand или group.')]
final class ProductReportTool extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ViewAnalytics);
    }

    public function handle(Request $request, ProductReport $report): ResponseFactory|Response
    {
        $this->permit($request, Permission::ViewAnalytics);

        $input = Validator::make($request->all(), [
            ...ReportSlice::rules(),
            'illiquid' => ['nullable', 'boolean'],
        ], ReportSlice::messages())->validate();

        $filters = ReportSlice::from($input);

        // Неликвид считается отдельным сканом витрины, и спрашивают его не
        // всегда: по умолчанию он не едет.
        $illiquid = (bool) ($input['illiquid'] ?? false);

        return $this->attempt(fn (): ResponseFactory => Response::structured(array_filter([
            'matrix' => $report->matrix($filters),
            'illiquid' => $illiquid ? $report->illiquid($filters) : null,
            'slice' => $filters->toArray(),
        ], static fn (mixed $value): bool => $value !== null)));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...ReportSlice::schema($schema),
            'illiquid' => $schema->boolean()
                ->description('Добавить перечень неликвида — товаров без движения. Считается отдельным проходом по витрине, поэтому по умолчанию не включён.'),
        ];
    }
}
