<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\ReportRequest;
use App\Support\Analytics\SalesReport;
use Illuminate\Http\JsonResponse;

/**
 * Продажи: сколько наторговали и на чём заработали.
 *
 * Вкладка отдаётся одним ответом, а не тремя запросами. Каждый из них ходит в
 * ClickHouse по двум миллионам строк, и раздельные обращения из браузера
 * означали бы несколько параллельных сканов ради одной картинки. Разрез
 * рейтинга — единственное, что запрашивается отдельно: его переключают, не
 * трогая остального.
 */
final class SalesController extends Controller
{
    public function __construct(private readonly SalesReport $report) {}

    public function index(ReportRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return response()->json([
            'data' => [
                'summary' => $this->report->summary($filters),
                'trend' => $this->report->trend($filters),
                'channels' => $this->report->channels($filters),
                'channel_trend' => $this->report->channelTrend($filters),
                'comparison' => $this->report->comparison($filters),
                'weekday' => $this->report->weekdayProfile($filters),
                'cost_structure' => $this->report->costStructure($filters),
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
