<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Analytics;

use App\Enums\Permission;
use App\Mcp\Support\ReportSlice;
use App\Mcp\Tools\BismarTool;
use App\Support\Analytics\CustomerReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Клиенты: кто покупает и как часто возвращается.
 *
 * Анонимная розница в счёт клиентов не идёт: за покупкой без карты не стоит
 * покупатель, которого можно посчитать, — и это считает сам отчёт, а не этот
 * инструмент.
 */
#[Name('customer_report')]
#[Title('Отчёт по клиентам')]
#[Description('Клиентская картина за период: итоги, разбивка по сегментам и типам заказов, удержание по месяцам прихода (когорты) и крупнейшие клиенты. Покупки без карты в число клиентов не входят — за ними не стоит покупатель, которого можно посчитать.')]
final class CustomerReportTool extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ViewAnalytics);
    }

    public function handle(Request $request, CustomerReport $report): ResponseFactory|Response
    {
        $this->permit($request, Permission::ViewAnalytics);

        $input = Validator::make($request->all(), [
            ...ReportSlice::rules(),
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('analytics.max_top_limit')],
            'cohorts' => ['nullable', 'boolean'],
        ], ReportSlice::messages())->validate();

        $filters = ReportSlice::from($input);
        $limit = (int) ($input['limit'] ?? config('analytics.top_limit'));

        return $this->attempt(fn (): ResponseFactory => Response::structured(array_filter([
            'summary' => $report->summary($filters),
            'segments' => $report->segments($filters),
            'order_types' => $report->orderTypes($filters),
            // Когорты — отдельный и самый дорогой проход: они считают
            // возвращаемость по месяцам прихода, а не продажи за период.
            'cohorts' => ($input['cohorts'] ?? false) ? $report->cohorts($filters, $limit) : null,
            'top' => $report->top($filters, $limit),
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
            'limit' => $schema->integer()->min(1)->description('Сколько строк в списке крупнейших клиентов и в когортах.'),
            'cohorts' => $schema->boolean()->description('Добавить удержание по месяцам прихода. Самый дорогой раздел отчёта, по умолчанию выключен.'),
        ];
    }
}
