<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Analytics;

use App\Enums\Permission;
use App\Mcp\Tools\BismarTool;
use App\Support\Sales\DepartmentReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * План и факт по отделам продаж.
 *
 * Период здесь один возможный — календарный месяц: планы ставят на месяц, и
 * сравнивать их с произвольным отрезком нечем. Поэтому своим инструментом, а не
 * разрезом продаж.
 *
 * Отделы собираются из сопоставления продавцов витрины со структурой компании
 * (sales_sellers). Пусто в ответе почти всегда означает не отсутствие продаж, а
 * несделанное сопоставление — об этом сказано прямо.
 */
#[Name('department_plan')]
#[Title('План и факт по отделам')]
#[Description('Выполнение плана продаж по отделам за календарный месяц: план, факт, процент выполнения — и то же по продавцам внутри отдела. Если ответ пуст, скорее всего не сделано сопоставление продавцов из торговой витрины с отделами (инструмент sales_sellers).')]
final class DepartmentPlan extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ViewAnalytics);
    }

    public function handle(Request $request, DepartmentReport $report): ResponseFactory|Response
    {
        $this->permit($request, Permission::ViewAnalytics);

        $input = $request->validate([
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ], ['month.regex' => 'Месяц передаётся как 2026-10.']);

        $month = ($input['month'] ?? null) === null
            ? CarbonImmutable::today()->startOfMonth()
            : CarbonImmutable::createFromFormat('Y-m-d', $input['month'].'-01')->startOfMonth();

        return $this->attempt(fn (): ResponseFactory => Response::structured([
            'month' => $month->format('Y-m'),
            'departments' => $report->forMonth($month),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()
                ->description('Месяц как 2026-10. По умолчанию текущий.'),
        ];
    }
}
