<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\CancelApproval;
use App\Actions\Lms\CompleteLesson;
use App\Actions\Lms\SubmitForApproval;
use App\Http\Controllers\Concerns\SendsForApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\StoreLessonRequest;
use App\Http\Requests\Lms\StoreModuleRequest;
use App\Http\Requests\Lms\SubmitForApprovalRequest;
use App\Http\Resources\Lms\CourseModuleResource;
use App\Http\Resources\Lms\LessonResource;
use App\Http\Resources\Lms\MaterialReviewResource;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\User;
use App\Support\Lms\BlockIdentifier;
use App\Support\Lms\RichTextExtractor;
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
    use SendsForApproval;

    public function __construct(
        private readonly CompleteLesson $completeLesson,
        private readonly RichTextExtractor $richText,
        private readonly BlockIdentifier $blocks,
    ) {}

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
        // Черновиком (2026-09-30): пока урок не согласован или не выложен
        // автором, людям его не показывают. Заводят урок пустым и пишут потом —
        // показывать эту минуту было нечестно и раньше, просто скрыть её было
        // нечем.
        $lesson = $module->allLessons()->create([
            'title' => $request->validated('title'),
            'slug' => $this->uniqueSlugWithinModule($module, (string) $request->validated('title')),
            ...$this->contentAttributes($request),
            'video_url' => $request->validated('video_url'),
            'duration_minutes' => $request->validated('duration_minutes'),
            'position' => $request->validated('position', $module->allLessons()->count()),
        ]);

        $this->refreshProgressFor($module->course);

        return LessonResource::make($lesson)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function updateLesson(
        StoreLessonRequest $request,
        Lesson $lesson,
        CancelApproval $approvals,
    ): LessonResource {
        $wasPublished = $lesson->isPublished();

        $lesson->update([
            'title' => $request->validated('title'),
            ...$this->contentAttributes($request),
            'video_url' => $request->validated('video_url'),
            'duration_minutes' => $request->validated('duration_minutes'),
            'position' => $request->validated('position', $lesson->position),
            ...$this->publicationAttributes($request, $lesson),
        ]);

        /** @var User $editor */
        $editor = $request->user();

        /*
         * Автор вправе не ждать согласования и выложить урок сам — то же, что у
         * документа и курса. Идущий круг тогда закрывается, а позванные узнают,
         * что их ответа больше не ждут (см. CancelApproval).
         */
        if (! $wasPublished && $lesson->isPublished()) {
            $approvals->handle($lesson, $editor, published: true);
        }

        // Уроков в курсе стало больше или меньше — у всех, кто его проходит,
        // сменился знаменатель.
        if ($wasPublished !== $lesson->isPublished()) {
            $this->refreshProgressFor($lesson->owningCourse());
        }

        return LessonResource::make($lesson);
    }

    /**
     * Выложить урок или снять его с публикации — если об этом просили.
     *
     * Не прислали — не трогаем: сохранений у урока много, и каждое из них не
     * должно решать за автора, показывать ли урок людям.
     *
     * @return array<string, mixed>
     */
    private function publicationAttributes(StoreLessonRequest $request, Lesson $lesson): array
    {
        if (! $request->has('is_published')) {
            return [];
        }

        // Дата ставится один раз: снятый и выложенный снова урок не становится
        // новым — люди уже видели его под этим адресом.
        return ['published_at' => $request->boolean('is_published') ? $lesson->published_at ?? now() : null];
    }

    /**
     * Отправить урок на согласование — тем же путём, что документ и курс.
     *
     * Право на правку курса: отправка это часть работы над уроком. Пока круг
     * идёт, урока людям не видно — он черновик, — а позванные его читают, см.
     * MaterialApprovals.
     */
    public function submitForApproval(
        SubmitForApprovalRequest $request,
        Lesson $lesson,
        SubmitForApproval $submit,
    ): MaterialReviewResource {
        return $this->sendForApproval($request, $lesson, $submit);
    }

    /** Отозвать отправку, пока никто не ответил. */
    public function withdrawApproval(Request $request, Lesson $lesson, CancelApproval $cancel): Response
    {
        return $this->withdrawFromApproval($request, $lesson, $cancel);
    }

    public function destroyLesson(Lesson $lesson): Response
    {
        $course = $lesson->loadMissing('module.course')->module->course;

        $lesson->delete();

        $this->refreshProgressFor($course);

        return response()->noContent();
    }

    /**
     * Keeps the rich document and its plain-text projection in step.
     *
     * `content` is what search runs against, so it is always derived from the
     * document rather than trusted from the client — the two cannot drift.
     *
     * Имена блокам присваиваются здесь же и тоже на сервере: на них ссылаются
     * строки таблицы урока, и имя, пришедшее от клиента, может оказаться
     * выдуманным или занятым чужим блоком.
     *
     * @return array{content: ?string, content_json: ?array<mixed>}
     */
    private function contentAttributes(StoreLessonRequest $request): array
    {
        /** @var array<mixed>|null $document */
        $document = $request->validated('content_json');

        if ($document === null) {
            return [
                'content' => $request->validated('content'),
                'content_json' => null,
            ];
        }

        $document = $this->blocks->assign($document);

        return [
            'content' => $this->richText->toPlainText($document),
            'content_json' => $document,
        ];
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

        while ($module->allLessons()->where('slug', $slug)->exists()) {
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
