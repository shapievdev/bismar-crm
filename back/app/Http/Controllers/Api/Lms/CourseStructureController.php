<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\CompleteLesson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\StoreLessonRequest;
use App\Http\Requests\Lms\StoreModuleRequest;
use App\Http\Resources\Lms\CourseModuleResource;
use App\Http\Resources\Lms\LessonResource;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Support\SlugGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Authoring the shape of a course: its modules and their lessons.
 */
final class CourseStructureController extends Controller
{
    public function __construct(private readonly CompleteLesson $completeLesson) {}

    public function storeModule(StoreModuleRequest $request, Course $course): JsonResponse
    {
        $module = $course->modules()->create([
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
            'position' => $request->validated('position', $course->modules()->count()),
        ]);

        return CourseModuleResource::make($module)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function updateModule(StoreModuleRequest $request, CourseModule $module): CourseModuleResource
    {
        $module->update([
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
            'position' => $request->validated('position', $module->position),
        ]);

        return CourseModuleResource::make($module);
    }

    public function destroyModule(CourseModule $module): Response
    {
        $module->delete();

        // Removing lessons changes the denominator of everyone's progress.
        $this->refreshProgressFor($module->course);

        return response()->noContent();
    }

    public function storeLesson(StoreLessonRequest $request, CourseModule $module, SlugGenerator $slugs): JsonResponse
    {
        $lesson = $module->lessons()->create([
            'title' => $request->validated('title'),
            'slug' => $this->uniqueSlugWithinModule($module, (string) $request->validated('title')),
            'content' => $request->validated('content'),
            'video_url' => $request->validated('video_url'),
            'duration_minutes' => $request->validated('duration_minutes'),
            'position' => $request->validated('position', $module->lessons()->count()),
        ]);

        $this->refreshProgressFor($module->course);

        return LessonResource::make($lesson)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function updateLesson(StoreLessonRequest $request, Lesson $lesson): LessonResource
    {
        $lesson->update([
            'title' => $request->validated('title'),
            'content' => $request->validated('content'),
            'video_url' => $request->validated('video_url'),
            'duration_minutes' => $request->validated('duration_minutes'),
            'position' => $request->validated('position', $lesson->position),
        ]);

        return LessonResource::make($lesson);
    }

    public function destroyLesson(Lesson $lesson): Response
    {
        $course = $lesson->loadMissing('module.course')->module->course;

        $lesson->delete();

        $this->refreshProgressFor($course);

        return response()->noContent();
    }

    /**
     * Slugs need only be unique inside their module, so this cannot reuse the
     * table-wide SlugGenerator.
     */
    private function uniqueSlugWithinModule(CourseModule $module, string $title): string
    {
        $base = Str::slug($title) ?: Str::lower(Str::random(8));
        $slug = $base;
        $suffix = 2;

        while ($module->lessons()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * Re-evaluates every learner's completion after the lesson set changed, so
     * a course cannot stay marked complete once new material is added.
     */
    private function refreshProgressFor(?Course $course): void
    {
        if ($course === null) {
            return;
        }

        $course->enrollments()->each(
            fn ($enrollment) => $this->completeLesson->refreshCourseCompletion($enrollment),
        );
    }
}
