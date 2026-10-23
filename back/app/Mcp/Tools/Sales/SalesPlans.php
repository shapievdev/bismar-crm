<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sales;

use App\Enums\Permission;
use App\Enums\SalesPlanKind;
use App\Mcp\Tools\BismarTool;
use App\Models\SalesPlan;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Планы продаж на месяц — какие стоят.
 *
 * Трёх видов сразу: на отдел, норматив на любого его продавца и личный. Одним
 * списком, потому что только рядом и видно, сходится ли раздача с тем, что
 * спрашивают с отдела.
 */
#[Name('sales_plans')]
#[Title('Планы продаж на месяц')]
#[Description('Планы продаж, поставленные на месяц: на отдел целиком (department), норматив на любого продавца отдела (member) и личный план продавца (person). Ставить и снимать — sales_set_plan.')]
final class SalesPlans extends BismarTool
{
    public function shouldRegister(): bool
    {
        return $this->allows(Permission::ManageSalesPlans);
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $this->permit($request, Permission::ManageSalesPlans);

        $input = $request->validate([
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ], ['month.regex' => 'Месяц передаётся как 2026-10.']);

        $month = ($input['month'] ?? null) === null
            ? CarbonImmutable::today()->startOfMonth()
            : CarbonImmutable::createFromFormat('Y-m-d', $input['month'].'-01')->startOfMonth();

        return Response::structured([
            'month' => $month->format('Y-m'),
            'plans' => SalesPlan::query()
                ->forMonth($month)
                ->get()
                ->map(static fn (SalesPlan $plan): array => [
                    'id' => $plan->id,
                    'kind' => $plan->kind->value,
                    'kind_label' => $plan->kind->label(),
                    'department_id' => $plan->department_id,
                    'sales_person_id' => $plan->sales_person_id,
                    'revenue' => (float) $plan->revenue,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()->description('Месяц как 2026-10. По умолчанию текущий.'),
        ];
    }

    /**
     * Виды плана — для описания, чтобы их не приходилось угадывать.
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        return SalesPlanKind::values();
    }
}
