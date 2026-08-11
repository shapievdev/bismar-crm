<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\ReportRequest;
use App\Support\Analytics\CustomerReport;
use Illuminate\Http\JsonResponse;

/**
 * Клиенты: кто покупает, кто вернулся и кто перестал.
 *
 * Все цифры вкладки — без анонимной розницы; почему именно так, объяснено в
 * CustomerReport.
 */
final class CustomerController extends Controller
{
    public function __construct(private readonly CustomerReport $report) {}

    public function index(ReportRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $limit = $request->limit();

        return response()->json([
            'data' => [
                'summary' => $this->report->summary($filters),
                'segments' => $this->report->segments($filters),
                'order_types' => $this->report->orderTypes($filters),
                'cohorts' => $this->report->cohorts($filters, $limit),
                'top' => $this->report->top($filters, $limit),
            ],
            'meta' => $filters->toArray(),
        ]);
    }
}
