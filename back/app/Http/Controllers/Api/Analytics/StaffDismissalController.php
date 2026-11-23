<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\StaffDismissalReportInput;
use App\Support\Staff\DismissalReport;
use App\Support\Staff\StaffQuery;
use App\Support\Staff\StaffScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class StaffDismissalController extends Controller
{
    public function __construct(private readonly DismissalReport $report, private readonly StaffScope $scope, private readonly StaffQuery $query) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($this->scope->allows($request->user()), 403);

        return $this->response($request);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($this->scope->allows($request->user()) && $request->user()->can(Permission::ManageUsers->value), 403);
        $input = $request->validate([
            'retainable' => ['present', 'nullable', 'integer', 'min:0'],
            'conditions_assessment' => ['nullable', 'string', 'max:5000'],
            'department_values' => ['present', 'array'],
            'department_values.*.id' => ['required', 'integer', 'distinct'],
            'department_values.*.average_headcount' => ['present', 'nullable', 'numeric', 'min:0', 'max:10000000'],
            'department_values.*.retainable' => ['present', 'nullable', 'integer', 'min:0'],
        ]);
        $filters = $this->query->from($request, $request->user());
        $current = $this->report->build($filters);
        $allowed = collect($current['departments'])->keyBy('id');
        $values = ['retainable' => $input['retainable'] === null ? null : (int) $input['retainable'], 'conditions_assessment' => $input['conditions_assessment'] ?? '', 'departments' => []];
        if (($input['retainable'] ?? 0) > $current['summary']['interviews']) {
            throw ValidationException::withMessages(['retainable' => 'Число удерживаемых не может превышать число прошедших интервью.']);
        }
        foreach ($input['department_values'] as $index => $row) {
            abort_unless($allowed->has($row['id']), 403);
            if (($row['retainable'] ?? 0) > $allowed[$row['id']]['interviews']) {
                throw ValidationException::withMessages(["department_values.$index.retainable" => 'Число удерживаемых не может превышать число интервью в подразделении.']);
            }
            $values['departments'][(string) $row['id']] = [
                'average_headcount' => $row['average_headcount'] === null ? null : (float) $row['average_headcount'],
                'retainable' => $row['retainable'] === null ? null : (int) $row['retainable'],
            ];
        }
        StaffDismissalReportInput::query()->updateOrCreate(['scope_key' => $this->report->key($filters)], [
            'filters' => $filters->toArray(), 'values' => $values, 'updated_by_id' => $request->user()->id,
        ]);

        return $this->response($request);
    }

    private function response(Request $request): JsonResponse
    {
        $data = $this->report->build($this->query->from($request, $request->user()));
        $canEdit = $request->user()->can(Permission::ManageUsers->value);
        if (! $canEdit) {
            $data['interview_reasons'] = [];
        }

        return response()->json(['data' => [...$data, 'can_edit' => $canEdit]])->header('Cache-Control', 'no-store, private');
    }
}
