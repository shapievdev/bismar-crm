<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Analytics;

use App\Enums\Permission;
use App\Mcp\Support\ReportSlice;
use App\Mcp\Tools\BismarTool;
use App\Support\Analytics\EmployeeReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Та же выручка, но знаменателем человек.
 *
 * Отличается от рейтинга по менеджерам (sales_breakdown с разрезом manager) не
 * цифрой, а вопросом: разрез отвечает «сколько принёс каждый», а этот отчёт —
 * «что происходит с теми, кто торгует»: средний чек, доля возвратов, маржа.
 *
 * Люди здесь — имена из витрины, а не учётные записи платформы: сопоставление
 * одних с другими ведётся отдельно, на экране отделов продаж.
 */
#[Name('employee_sales')]
#[Title('Продажи по сотрудникам')]
#[Description('Выручка, прибыль, средний чек и доля возвратов по каждому продающему сотруднику за период. Имена приходят из торговой витрины, а не из учётных записей платформы.')]
final class EmployeeSales extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ViewAnalytics);
    }

    public function handle(Request $request, EmployeeReport $report): ResponseFactory|Response
    {
        $this->permit($request, Permission::ViewAnalytics);

        $input = Validator::make($request->all(), [
            ...ReportSlice::rules(),
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('analytics.max_top_limit')],
        ], ReportSlice::messages())->validate();

        $filters = ReportSlice::from($input);

        return $this->attempt(fn (): ResponseFactory => Response::structured([
            'summary' => $report->summary($filters),
            'ranking' => $report->ranking($filters, (int) ($input['limit'] ?? config('analytics.top_limit'))),
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
            'limit' => $schema->integer()->min(1)->description('Сколько сотрудников вернуть.'),
        ];
    }
}
