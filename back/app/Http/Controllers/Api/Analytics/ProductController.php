<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\ReportRequest;
use App\Support\Analytics\ProductReport;
use Illuminate\Http\JsonResponse;

/**
 * Товары: что продаётся, что лежит и на чём зарабатывают.
 */
final class ProductController extends Controller
{
    public function __construct(private readonly ProductReport $report) {}

    public function index(ReportRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return response()->json([
            'data' => [
                'matrix' => $this->report->matrix($filters),
                'illiquid' => $this->report->illiquid($filters),
            ],
            'meta' => $filters->toArray(),
        ]);
    }

    public function breakdown(ReportRequest $request, string $dimension): JsonResponse
    {
        $filters = $request->filters();

        return response()->json([
            'data' => $this->report->breakdown($filters, $dimension, $request->limit()),
            'meta' => [...$filters->toArray(), 'dimension' => $dimension],
        ]);
    }
}
