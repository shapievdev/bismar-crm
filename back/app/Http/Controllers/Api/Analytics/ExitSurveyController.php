<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Models\EmployeeExitSurvey;
use App\Support\Search\Substring;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ExitSurveyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'submitted', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:200'],
            'department' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $base = EmployeeExitSurvey::query();
        Substring::apply($base, $filters['search'] ?? null, ["employee->>'name'"]);
        if (! empty($filters['department'])) {
            $base->where('employee->department', $filters['department']);
        }
        if (! empty($filters['from'])) {
            $base->whereDate('dismissed_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $base->whereDate('dismissed_at', '<=', $filters['to']);
        }
        $summary = [
            'pending' => (clone $base)->pending()->count(),
            'submitted' => (clone $base)->whereNotNull('submitted_at')->count(),
            'cancelled' => (clone $base)->whereNotNull('cancelled_at')->count(),
        ];
        $breakdowns = [];
        foreach ((clone $base)->whereNotNull('submitted_at')->cursor() as $survey) {
            foreach ($survey->definition['fields'] as $field) {
                if ($field['type'] !== 'radio') {
                    continue;
                }
                $key = $survey->definition['version'].':'.$field['id'];
                $breakdowns[$key] ??= ['label' => $field['label'], 'options' => array_fill_keys([...$field['options'], ...(! empty($field['other']) ? ['Другое'] : [])], 0)];
                $answer = $survey->answers[$field['id']] ?? '';
                $label = $answer === 'other' ? 'Другое' : $answer;
                if (isset($breakdowns[$key]['options'][$label])) {
                    $breakdowns[$key]['options'][$label]++;
                }
            }
        }
        $rows = clone $base;
        match ($filters['status'] ?? null) {
            'pending' => $rows->pending(),
            'submitted' => $rows->whereNotNull('submitted_at'),
            'cancelled' => $rows->whereNotNull('cancelled_at'),
            default => null,
        };
        $page = $rows->latest('id')->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (EmployeeExitSurvey $survey) => $this->row($survey)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'summary' => $summary,
            'breakdowns' => array_values($breakdowns),
            'departments' => EmployeeExitSurvey::query()->get(['employee'])->pluck('employee.department')->filter()->unique()->sort()->values(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(EmployeeExitSurvey $survey): JsonResponse
    {
        return response()->json(['data' => [...$this->row($survey), 'definition' => $survey->definition, 'answers' => $survey->answers]])->header('Cache-Control', 'no-store, private');
    }

    private function row(EmployeeExitSurvey $survey): array
    {
        return [
            'id' => $survey->id, 'user_id' => $survey->user_id, 'employee' => $survey->employee,
            'status' => $survey->status(), 'dismissed_at' => $survey->dismissed_at->toIso8601String(),
            'assigned_at' => $survey->created_at->toIso8601String(),
            'submitted_at' => $survey->submitted_at?->toIso8601String(),
            'cancelled_at' => $survey->cancelled_at?->toIso8601String(),
        ];
    }
}
