<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Sales;

use App\Actions\Sales\SuggestPlansFromHistory;
use App\Enums\SalesPlanKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\PlanRequest;
use App\Models\SalesPlan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Планы продаж на месяц.
 *
 * Трёх видов сразу — на отдел, норматив на любого его продавца и личный, — и
 * приезжают они одним списком: экран, на котором их ставят, показывает все три
 * в одной таблице, потому что только рядом и видно, сходится ли раздача с тем,
 * что спрашивают с отдела.
 */
final class PlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $month = $this->month($request);

        $plans = SalesPlan::query()
            ->forMonth($month)
            ->get()
            ->map(static fn (SalesPlan $plan): array => [
                'id' => $plan->id,
                'kind' => $plan->kind->value,
                'department_id' => $plan->department_id,
                'sales_person_id' => $plan->sales_person_id,
                'revenue' => (float) $plan->revenue,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $plans,
            'meta' => ['period' => $month->format('Y-m')],
        ]);
    }

    /**
     * Поставить план или переписать поставленный.
     *
     * Переписать, а не завести второй: план на месяц у цели один, и два
     * означали бы, что непонятно, по какому спрашивать.
     */
    public function update(PlanRequest $request): JsonResponse
    {
        $kind = $request->kind();

        $plan = SalesPlan::query()->updateOrCreate(
            [
                'period' => $request->month()->toDateString(),
                'kind' => $kind,
                // Для личного плана отдел не хранится: он у продавца, и
                // записанный вторым местом разошёлся бы с ним при переводе.
                'department_id' => $kind === SalesPlanKind::Person ? null : $request->integer('department_id'),
                'sales_person_id' => $kind === SalesPlanKind::Person ? $request->integer('sales_person_id') : null,
            ],
            ['revenue' => $request->float('revenue')],
        );

        return response()->json(['data' => [
            'id' => $plan->id,
            'kind' => $plan->kind->value,
            'department_id' => $plan->department_id,
            'sales_person_id' => $plan->sales_person_id,
            'revenue' => (float) $plan->revenue,
        ]]);
    }

    /**
     * Расставить планы по умолчанию — по факту закрытых месяцев.
     *
     * Отдельным нажатием, а не само при открытии месяца: это решение о мерке,
     * по которой потом спросят, и приниматься оно должно человеком.
     */
    public function suggest(Request $request, SuggestPlansFromHistory $suggest): JsonResponse
    {
        return response()->json(['data' => $suggest($this->month($request))]);
    }

    /** Снять план — не то же, что поставить ноль: ноль тоже требование. */
    public function destroy(SalesPlan $plan): JsonResponse
    {
        $plan->delete();

        return response()->json(['data' => null]);
    }

    private function month(Request $request): CarbonImmutable
    {
        $period = $request->string('period')->value();

        return preg_match('/^\d{4}-\d{2}$/', $period) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfMonth()
            : CarbonImmutable::today()->startOfMonth();
    }
}
