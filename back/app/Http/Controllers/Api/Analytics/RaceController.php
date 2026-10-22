<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Support\Sales\RaceReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Битва отделов — табло для зала.
 *
 * Тот же месяц, что у вкладки «Отделы»: план ставится на календарный месяц, и
 * считать гонку по другим границам значило бы спорить с меркой.
 */
final class RaceController extends Controller
{
    public function __construct(private readonly RaceReport $report) {}

    public function __invoke(Request $request): JsonResponse
    {
        $period = $request->string('period')->value();

        $month = preg_match('/^\d{4}-\d{2}$/', $period) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfMonth()
            : CarbonImmutable::today()->startOfMonth();

        return response()->json(['data' => $this->report->forMonth($month)]);
    }
}
