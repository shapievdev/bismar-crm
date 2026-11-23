<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Enums\DismissalReason;
use App\Models\Department;
use App\Models\EmployeeExitSurvey;
use App\Models\StaffDismissalReportInput;
use App\Support\Structure\DepartmentReach;
use Illuminate\Support\Collection;

final readonly class DismissalReport
{
    public function __construct(private StaffReport $staff, private DepartmentReach $reach) {}

    public function key(StaffFilters $filters): string
    {
        $scope = $filters->toArray();
        if (is_array($scope['departments'])) {
            sort($scope['departments']);
        }
        sort($scope['tags']);

        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }

    public function build(StaffFilters $filters): array
    {
        $people = $this->staff->matching($filters)
            ->whereBetween('dismissed_at', [$filters->from, $filters->to])
            ->with('departments:id,name')->get();
        // Only the survey for this dismissal counts. A previous employment
        // cycle or a cancelled invitation is not an interview for this one.
        $surveys = EmployeeExitSurvey::query()->whereIn('user_id', $people->modelKeys())
            ->whereNull('cancelled_at')->get()->toBase()->filter(function ($survey) use ($people): bool {
                $person = $people->firstWhere('id', $survey->user_id);

                return $person && $survey->dismissed_at->equalTo($person->dismissed_at);
            })->groupBy('user_id')->map(fn ($rows) => $rows->sortByDesc('id')->first());
        $input = StaffDismissalReportInput::query()->where('scope_key', $this->key($filters))->first();
        $values = $input?->values ?? [];
        $departments = Department::query()
            ->when($filters->departmentIds !== null, fn ($q) => $q->whereIn('id', $filters->departmentIds))
            ->ordered()->get(['id', 'name', 'parent_id']);

        $rows = $departments->map(function ($department) use ($people, $surveys, $values, $departments): array {
            $branch = $this->reach->branch([(int) $department->id]);
            $members = $people->filter(fn ($person) => $person->departments->pluck('id')->intersect($branch)->isNotEmpty());
            $manual = $values['departments'][(string) $department->id] ?? [];

            return ['id' => $department->id, 'name' => $department->name,
                'parent_id' => $departments->contains('id', $department->parent_id) ? $department->parent_id : null,
                ...$this->metrics($members, $surveys, $manual),
                'average_headcount' => $manual['average_headcount'] ?? null,
                'turnover' => $this->percent($members->count(), $manual['average_headcount'] ?? null),
            ];
        })->values()->all();
        $summary = $this->metrics($people, $surveys, $values);
        $counts = $people->countBy(fn ($p) => $p->dismissal_reason?->value ?? 'unknown');
        $reasons = $counts->map(fn ($count, $reason) => [
            'label' => DismissalReason::tryFrom($reason)?->label() ?? 'Причина не указана',
            'count' => $count, 'share' => $this->percent($count, $people->count()),
        ])->sortByDesc('count')->values()->all();
        $interviewReasons = $surveys->filter(fn ($s) => $s->submitted_at !== null)
            ->map(fn ($s) => trim(preg_replace('/\s+/u', ' ', (string) ($s->answers['reason'] ?? ''))))
            ->filter()->groupBy(fn ($text) => mb_strtolower($text))
            ->map(fn ($texts) => ['label' => $texts->first(), 'count' => $texts->count(),
                'share' => $this->percent($texts->count(), $summary['interviews'])])
            ->sortByDesc('count')->values()->all();

        return [
            'period' => ['from' => $filters->from->toDateString(), 'to' => $filters->to->toDateString()],
            'summary' => $summary, 'departments' => $rows, 'reasons' => $reasons,
            'interview_reasons' => $interviewReasons,
            'conditions_assessment' => $values['conditions_assessment'] ?? '',
            'updated_at' => $input?->updated_at?->toIso8601String(),
        ];
    }

    private function metrics(Collection $people, Collection $surveys, array $manual): array
    {
        $own = $surveys->only($people->modelKeys());
        $interviews = $own->filter(fn ($s) => $s->submitted_at !== null)->count();
        $early = $people->filter(fn ($p) => $p->hired_at !== null
            && $p->dismissed_at->greaterThanOrEqualTo($p->hired_at)
            && $p->dismissed_at->toImmutable()->startOfDay()->lessThan($p->hired_at->toImmutable()->addMonthsNoOverflow(3)))->count();
        $retainable = $manual['retainable'] ?? null;

        return [
            'left' => $people->count(), 'interviews' => $interviews,
            'interview_coverage' => $this->percent($interviews, $people->count()),
            'retainable' => $retainable,
            'retainable_share' => $retainable !== null ? $this->percent($retainable, $interviews) : null,
            'early_left' => $early, 'early_share' => $this->percent($early, $people->count()),
            'without_hire_date' => $people->whereNull('hired_at')->count(),
            'pending' => $own->filter(fn ($s) => $s->submitted_at === null)->count(),
            'without_survey' => $people->count() - $own->count(),
        ];
    }

    private function percent(int|float $count, int|float|null $base): ?float
    {
        return $base !== null && $base > 0 ? round($count / $base * 100, 1) : null;
    }
}
