<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Regulation;
use App\Support\Lms\ProgressReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Admission and confirmation only; test answers and scores stay in analytics. */
final class ReadershipController extends Controller
{
    public function course(Course $course, ProgressReport $report): JsonResponse
    {
        Gate::authorize('update', $course);

        return $this->respond($report->onCourse($course), true);
    }

    public function lesson(Lesson $lesson, ProgressReport $report): JsonResponse
    {
        Gate::authorize('update', $lesson->course());

        return $this->respond($report->onLesson($lesson));
    }

    public function material(Regulation $regulation, ProgressReport $report): JsonResponse
    {
        Gate::authorize('update', $regulation);

        return $this->respond($report->onRegulation($regulation));
    }

    private function respond(array $report, bool $course = false): JsonResponse
    {
        $people = collect($report['people']);
        [$done, $pending] = $people->partition(fn (array $person): bool => $course
            ? $person['status'] === 'completed'
            : $person['is_done']);
        $identity = static fn (array $person): array => [
            'id' => $person['id'], 'name' => $person['name'], 'job_title' => $person['job_title'],
            'avatar_url' => $person['avatar_url'],
            'acknowledged_at' => $course
                ? ($person['status'] === 'completed' ? $person['completed_at'] : null)
                : ($person['is_done'] ? $person['done_at'] : null),
        ];

        return response()->json(['data' => [
            'total' => $people->count(),
            'acknowledged' => $done->map($identity)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'pending' => $pending->map($identity)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        ]])->header('Cache-Control', 'no-store, private');
    }
}
