<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\CompleteLesson;
use App\Actions\Lms\EnrollLearner;
use App\Actions\Lms\GradeQuizAttempt;
use App\Exceptions\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SubmitQuizRequest;
use App\Http\Resources\Lms\EnrollmentResource;
use App\Http\Resources\Lms\LessonResource;
use App\Http\Resources\Lms\QuizAttemptResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\Lms\ProgressCalculator;
use App\Support\Lms\QuizReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Everything a learner does: enrolling, reading lessons, marking them done and
 * sitting quizzes.
 */
final class LearningController extends Controller
{
    public function __construct(
        private readonly ProgressCalculator $progress,
        private readonly CompleteLesson $completeLesson,
        private readonly QuizReview $review,
    ) {}

    /**
     * Разбор одной попытки — своей и только своей.
     *
     * Отдельным маршрутом, потому что к разбору возвращаются: сдал с третьего
     * раза, а через месяц перечитываешь урок и хочешь вспомнить, что тогда
     * понял неверно.
     */
    public function showAttempt(Request $request, QuizAttempt $attempt): QuizAttemptResource
    {
        /** @var User $learner */
        $learner = $request->user();

        // 404, а не 403: чужая попытка — не то, о существовании чего стоит
        // сообщать, и уж точно не то, о чём стоит спорить.
        if ($attempt->user_id !== $learner->getKey()) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $attempt->setAttribute('review', $this->review->of($attempt, $learner));

        return QuizAttemptResource::make($attempt);
    }

    /**
     * The signed-in learner's own courses, each with its progress.
     */
    public function myEnrollments(Request $request): AnonymousResourceCollection
    {
        /** @var User $learner */
        $learner = $request->user();

        $enrollments = Enrollment::query()
            ->where('user_id', $learner->getKey())
            // Курс могли удалить, а запись на него осталась — прогресс по нему
            // хранится ради возможного восстановления. Показывать её нечем:
            // материала, к которому она относится, в базе знаний больше нет.
            //
            // Из приватного курса человека могли и вывести. Прогресс так же
            // остаётся — вернут доступ, и курс вернётся в список.
            ->whereHas('course', fn ($query) => $query->visibleTo($learner))
            // Только то, за что человек взялся: нажал «Начать обучение» или
            // прошёл хотя бы один урок. Иначе список — история просмотров, где
            // начатое не найти.
            ->whereNotNull('started_at')
            ->with(['course.author', 'completions'])
            ->latest('enrolled_at')
            ->get()
            ->each(fn (Enrollment $enrollment) => $this->attachProgress($enrollment));

        return EnrollmentResource::collection($enrollments);
    }

    public function enroll(Request $request, Course $course, EnrollLearner $enrollLearner): JsonResponse
    {
        if (Gate::denies('view', $course)) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        /** @var User $learner */
        $learner = $request->user();

        $enrollment = $enrollLearner->handle($course, $learner);
        $enrollment->load('course', 'completions');

        return EnrollmentResource::make($this->attachProgress($enrollment))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function showLesson(Request $request, Lesson $lesson): LessonResource
    {
        $course = $this->courseFor($lesson);

        if (Gate::denies('view', $course)) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        // Проверяющий едет вместе с тестом: сотруднику важно знать, кому уйдёт
        // работа, — «ждёт проверки» без имени звучит как «ждёт неизвестно чего».
        $lesson->load('attachments', 'quiz.questions.options', 'quiz.examiner:id,last_name,first_name,middle_name');

        // Строки таблицы едут вместе с уроком: редактор правит их на той же
        // странице, а читателю они показывают, что урок разбирает.
        $lesson->loadAnswers();

        // A knowledge base has no sign-up step: opening a lesson is enough to
        // start tracking progress, so the enrolment is created on the spot.
        $enrollment = $this->ensureEnrollment($request, $course);

        $lesson->setAttribute(
            'is_completed_by_learner',
            $enrollment?->completions()->where('lesson_id', $lesson->getKey())->exists() ?? false,
        );

        // Что мешает закрыть урок: курс проходят по порядку, и кнопку
        // «Отметить пройденным» экран гасит, называя причину, а не даёт нажать
        // её ради отказа с сервера.
        $lesson->setAttribute(
            'blocked_by',
            $enrollment === null ? null : $this->completeLesson->blockedBy($enrollment, $lesson)?->only('id', 'title'),
        );

        // Neighbours let the player offer "previous" and "next" without the SPA
        // having to fetch and flatten the whole course outline.
        $lesson->setAttribute('neighbours', $this->neighboursOf($course, $lesson));
        $lesson->setAttribute('course_title', $course->title);
        $lesson->setAttribute('course_slug', $course->slug);

        // A learner's own past attempts, so the player can show their history.
        $lesson->setAttribute('own_attempts', $lesson->quiz === null ? [] : $lesson->quiz
            ->attempts()
            ->where('user_id', $request->user()?->getKey())
            ->with('reviewer:id,last_name,first_name,middle_name')
            ->latest('completed_at')
            ->limit(10)
            ->get()
            ->map(fn ($attempt): array => [
                'id' => $attempt->id,
                'score' => $attempt->score,
                'passed' => $attempt->passed,
                'completed_at' => $attempt->completed_at?->toIso8601String(),

                // Состояние аттестации: дошла ли работа, ответили ли, а если не
                // зачли — то почему. У обычного теста это всегда «оценено
                // приложением», и экран такую строку не показывает.
                'review_status' => $attempt->review_status->value,
                'review_status_label' => $attempt->review_status->label(),
                'review_comment' => $attempt->review_comment,
                'reviewed_at' => $attempt->reviewed_at?->toIso8601String(),
                'reviewed_by' => $attempt->reviewer?->name,
            ])->all());

        return LessonResource::make($lesson);
    }

    /**
     * @throws ConflictException
     */
    public function completeLesson(Request $request, Lesson $lesson): JsonResponse
    {
        $enrollment = $this->requireEnrollment($request, $this->courseFor($lesson));
        $enrollment = $this->completeLesson->handle($enrollment, $lesson);

        return response()->json([
            'data' => [
                'progress' => $this->progress->percentage($enrollment),
                'is_completed' => $enrollment->isCompleted(),
            ],
        ]);
    }

    /**
     * @throws ConflictException
     */
    public function submitQuiz(
        SubmitQuizRequest $request,
        Lesson $lesson,
        GradeQuizAttempt $gradeQuizAttempt,
    ): JsonResponse {
        $enrollment = $this->requireEnrollment($request, $this->courseFor($lesson));

        $lesson->loadMissing('quiz');

        if ($lesson->quiz === null) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        /** @var User $learner */
        $learner = $request->user();

        $attempt = $gradeQuizAttempt->handle($lesson->quiz, $learner, $request->answers(), $enrollment);

        // Разбор прикладывается сразу: человек хочет знать, где ошибся, ровно
        // в ту секунду, когда увидел результат, а не после отдельного запроса.
        $attempt->setAttribute('review', $this->review->of($attempt, $learner));

        return QuizAttemptResource::make($attempt)
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * The lessons either side of this one, in course reading order.
     *
     * @return array{previous: array{id: int, title: string}|null, next: array{id: int, title: string}|null}
     */
    private function neighboursOf(Course $course, Lesson $lesson): array
    {
        $lessons = $course->lessons()->get(['lessons.id', 'lessons.title']);
        $index = $lessons->search(fn (Lesson $candidate): bool => $candidate->is($lesson));

        $at = function (int|false $position) use ($lessons): ?array {
            $found = $position === false ? null : $lessons->get($position);

            return $found === null ? null : ['id' => $found->id, 'title' => $found->title];
        };

        return [
            'previous' => $index === false || $index === 0 ? null : $at($index - 1),
            'next' => $index === false ? null : $at($index + 1),
        ];
    }

    private function attachProgress(Enrollment $enrollment): Enrollment
    {
        return $enrollment->setAttribute('progress_percentage', $this->progress->percentage($enrollment));
    }

    private function courseFor(Lesson $lesson): Course
    {
        $course = $lesson->loadMissing('module.course')->module->course;

        // A lesson without a course would mean corrupt data, not a bad request.
        abort_if($course === null, HttpResponse::HTTP_NOT_FOUND);

        return $course;
    }

    private function enrollmentFor(Request $request, Course $course): ?Enrollment
    {
        return Enrollment::query()
            ->where('course_id', $course->getKey())
            ->where('user_id', $request->user()?->getKey())
            ->first();
    }

    /**
     * Returns the reader's enrolment, creating it if the material is published.
     *
     * Draft material is previewed by editors, whose reading is not progress
     * worth recording, so no enrolment is created for it.
     */
    private function ensureEnrollment(Request $request, Course $course): ?Enrollment
    {
        $existing = $this->enrollmentFor($request, $course);

        if ($existing !== null || ! $course->status->isOpenToLearners()) {
            return $existing;
        }

        /** @var User $reader */
        $reader = $request->user();

        // Открыть урок — ещё не взяться за курс. Запись заводится, чтобы
        // прогресс было куда писать, но начатой не считается.
        return app(EnrollLearner::class)->handle($course, $reader, deliberate: false);
    }

    /**
     * @throws ConflictException
     */
    private function requireEnrollment(Request $request, Course $course): Enrollment
    {
        $enrollment = $this->ensureEnrollment($request, $course);

        if ($enrollment === null) {
            throw new ConflictException('Материал не опубликован — прогресс не сохраняется.');
        }

        return $enrollment;
    }
}
