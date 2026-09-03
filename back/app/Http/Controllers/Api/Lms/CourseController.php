<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\SaveCourse;
use App\Actions\Lms\StoreCourseCover;
use App\Enums\CourseStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\StoreCourseRequest;
use App\Http\Requests\Lms\StoreCoverRequest;
use App\Http\Requests\Lms\UpdateCourseRequest;
use App\Http\Resources\Lms\CourseResource;
use App\Models\Category;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Support\Lms\ProgressCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class CourseController extends Controller
{
    public function __construct(private readonly ProgressCalculator $progress) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $courses = Course::query()
            ->with('author', 'category')
            ->withCount(['lessons', 'enrollments'])
            // Приватные курсы — только свои: чужой закрытый курс не должен
            // попадать в каталог даже названием.
            ->visibleTo($user)
            ->matching($request->query('search'))
            ->when(
                $request->filled('category'),
                // Choosing a category includes everything nested beneath it,
                // otherwise a parent category would look empty.
                fn ($query) => $query->whereIn('category_id', $this->branchIdsFor((string) $request->query('category'))),
            )
            ->when(
                // Unpublished courses stay hidden from learners.
                $user->cannot(Permission::UpdateCourses->value),
                fn ($query) => $query->openToLearners(),
                fn ($query) => $query->when(
                    $request->filled('status'),
                    fn ($query) => $query->where('status', $request->query('status')),
                ),
            )
            // NULLS LAST because Postgres puts them first on a DESC sort, and
            // `published_at` is null for everything unpublished — without it a
            // draft or an archived course opens the catalogue.
            ->orderByRaw('published_at DESC NULLS LAST')
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        return CourseResource::collection($courses);
    }

    public function show(Request $request, Course $course): CourseResource
    {
        if (Gate::denies('view', $course)) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        // Ответственные — всем, кто курс видит: к ним идут с вопросом, на
        // который материал не ответил, и знать о них должен читатель, а не
        // редактор.
        $course->load(['author', 'category', 'experts', 'modules.lessons.quiz'])
            ->loadCount(['lessons', 'enrollments', 'members']);

        $course->setAttribute('learner_enrollment', $this->enrollmentPayload($request, $course));

        return CourseResource::make($course);
    }

    public function store(StoreCourseRequest $request, SaveCourse $saveCourse): JsonResponse
    {
        /** @var User $author */
        $author = $request->user();

        /** @var array{title: string, summary?: ?string, description?: ?string, status: string} $attributes */
        $attributes = $request->validated();

        return CourseResource::make($saveCourse->create($attributes, $author))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(UpdateCourseRequest $request, Course $course, SaveCourse $saveCourse): CourseResource
    {
        /** @var array{title: string, summary?: ?string, description?: ?string, status: string} $attributes */
        $attributes = $request->validated();

        return CourseResource::make($saveCourse->update($course, $attributes));
    }

    public function destroy(Request $request, Course $course): Response
    {
        // Soft delete: a course carries learner progress worth recovering.
        // Удалённое лежит в корзине, и первый вопрос у всякого, кто её
        // открывает, — чьих рук дело; дата без имени на него не отвечает.
        $course->forceFill(['deleted_by' => $request->user()?->getKey()])->save();
        $course->delete();

        return response()->noContent();
    }

    public function storeCover(
        StoreCoverRequest $request,
        Course $course,
        StoreCourseCover $storeCover,
    ): CourseResource {
        return CourseResource::make($storeCover->handle($course, $request->file('cover')));
    }

    public function destroyCover(Course $course, StoreCourseCover $storeCover): CourseResource
    {
        return CourseResource::make($storeCover->remove($course));
    }

    /**
     * The status vocabulary, so the editor never hardcodes it.
     */
    public function statuses(): JsonResponse
    {
        $statuses = array_map(
            static fn (CourseStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ],
            CourseStatus::cases(),
        );

        return response()->json(['data' => $statuses]);
    }

    /**
     * Ids of the named category and everything nested under it.
     *
     * @return list<int>
     */
    private function branchIdsFor(string $slug): array
    {
        $category = Category::query()->with('descendants')->firstWhere('slug', $slug);

        return $category?->branchIds() ?? [];
    }

    /**
     * The signed-in learner's own progress, or null if they are not enrolled.
     *
     * @return array<string, mixed>|null
     */
    private function enrollmentPayload(Request $request, Course $course): ?array
    {
        $enrollment = Enrollment::query()
            ->where('course_id', $course->getKey())
            ->where('user_id', $request->user()?->getKey())
            ->with('completions')
            ->first();

        if ($enrollment === null) {
            return null;
        }

        return [
            'id' => $enrollment->id,
            'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
            'completed_at' => $enrollment->completed_at?->toIso8601String(),
            'is_completed' => $enrollment->isCompleted(),
            'progress' => $this->progress->percentage($enrollment),
            'completed_lesson_ids' => $enrollment->completions->pluck('lesson_id')->values(),
        ];
    }
}
