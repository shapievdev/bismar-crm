<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\ReportRequest;
use App\Support\Analytics\EmployeeReport;
use Illuminate\Http\JsonResponse;

/**
 * Продажи по сотрудникам: кто сколько наторговал.
 *
 * Вкладка отдаётся одним ответом: рейтинг здесь не переключаемый разрез, как в
 * продажах, а сама вкладка — из него же собираются и средний заказ, и число
 * клиентов, и точки на графике «оборот против маржи». Разделённый на запросы,
 * он приехал бы трижды.
 */
final class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeReport $report) {}

    public function index(ReportRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return response()->json([
            'data' => [
                'summary' => $this->report->summary($filters),
                'ranking' => $this->report->ranking($filters, $request->limit()),
            ],
            'meta' => $filters->toArray(),
        ]);
    }
}
