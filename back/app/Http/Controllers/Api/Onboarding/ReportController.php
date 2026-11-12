<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Onboarding;

use App\Http\Controllers\Controller;
use App\Support\Analytics\OnboardingReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HR-отчёт по адаптации. Фильтры: период (по дате старта), подразделение
 * (с вложенными), шаблон маршрута.
 */
final class ReportController extends Controller
{
    public function show(Request $request, OnboardingReport $report): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'version_id' => ['nullable', 'integer', 'exists:onboarding_templates,id'],
            'store' => ['nullable', 'string', 'max:255'],
            'template_id' => ['nullable', 'integer', 'exists:onboarding_templates,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        return response()->json(['data' => $report->build([
            'version_id' => $validated['version_id'] ?? null,
            'store' => $validated['store'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'template_id' => isset($validated['template_id']) ? (int) $validated['template_id'] : null,
            'department_id' => isset($validated['department_id']) ? (int) $validated['department_id'] : null,
        ])]);
    }
}
