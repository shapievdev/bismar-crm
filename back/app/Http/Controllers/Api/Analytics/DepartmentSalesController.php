<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Support\Sales\DepartmentReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * План и факт по отделам за месяц.
 *
 * Период здесь не такой, как на остальных вкладках, и это решение
 * пользователя (2026-10-21): планы ставят на календарный месяц, и отчёт по ним
 * обязан идти теми же границами. Открывается текущий месяц с первого числа по
 * сегодня, переключается на любой прошлый.
 */
final class DepartmentSalesController extends Controller
{
    public function __construct(private readonly DepartmentReport $report) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->report->forMonth($this->month($request))]);
    }

    /**
     * Месяц из запроса — или текущий, когда его не прислали.
     *
     * Непонятное значение не отказ, а текущий месяц: это адресная строка, её
     * правят руками, и показать сегодняшний отчёт полезнее, чем ошибку.
     */
    private function month(Request $request): CarbonImmutable
    {
        $period = $request->string('period')->value();

        return preg_match('/^\d{4}-\d{2}$/', $period) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfMonth()
            : CarbonImmutable::today()->startOfMonth();
    }
}
