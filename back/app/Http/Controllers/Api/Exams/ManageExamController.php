<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Exams;

use App\Actions\Exams\AssignExam;
use App\Actions\Exams\ChangeExamStatus;
use App\Actions\Exams\SaveExam;
use App\Actions\Lms\SaveQuiz;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exams\AssignExamRequest;
use App\Http\Requests\Exams\ChangeExamStatusRequest;
use App\Http\Requests\Exams\SaveExamRequest;
use App\Http\Requests\Lms\SaveQuizRequest;
use App\Http\Resources\Exams\ExamResource;
use App\Http\Resources\Lms\CoursePersonResource;
use App\Http\Resources\Lms\QuizAttemptResource;
use App\Http\Resources\Lms\QuizResource;
use App\Models\Exam;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\Exams\ExamResults;
use App\Support\Lms\QuizReview;
use App\Support\Staff\EmployedPeople;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Ведение экзаменов: собрать, выпустить, назначить, посмотреть результаты.
 *
 * Вся группа закрыта правом `exams.manage` на маршруте — здесь прав никто не
 * спрашивает. Вопросы ведёт SaveQuiz — тот же, что у уроков и документов:
 * редактор тестов в приложении один.
 */
final class ManageExamController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ExamResource::collection(
            Exam::query()
                ->withCount('assignments')
                ->with('author')
                ->latest('id')
                ->get(),
        );
    }

    public function store(SaveExamRequest $request, SaveExam $save): ExamResource
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{title: string, description?: ?string, opens_at?: ?string, closes_at?: ?string, time_limit_minutes?: ?int, shuffle_questions?: bool} $attributes */
        $attributes = $request->validated();

        return ExamResource::make($save->handle(null, $attributes, $actor));
    }

    public function show(Exam $exam): ExamResource
    {
        return ExamResource::make(
            $exam->loadMissing('quiz.questions.options', 'quiz.examiner', 'author')->loadCount('assignments'),
        );
    }

    public function update(SaveExamRequest $request, Exam $exam, SaveExam $save): ExamResource
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{title: string, description?: ?string, opens_at?: ?string, closes_at?: ?string, time_limit_minutes?: ?int, shuffle_questions?: bool} $attributes */
        $attributes = $request->validated();

        return ExamResource::make($save->handle($exam, $attributes, $actor));
    }

    /**
     * Мягкое удаление: экзамен уходит из списков, сданные работы остаются.
     */
    public function destroy(Exam $exam): Response
    {
        $exam->delete();

        return response()->noContent();
    }

    public function status(ChangeExamStatusRequest $request, Exam $exam, ChangeExamStatus $change): ExamResource
    {
        return ExamResource::make($change->handle($exam, $request->status()));
    }

    /**
     * Вопросы экзамена — целиком, как в редакторе теста урока: присланное
     * заменяет бывшее, вопросы со своим номером правятся на месте.
     */
    public function saveQuiz(SaveQuizRequest $request, Exam $exam, SaveQuiz $save): QuizResource
    {
        /** @var array{title: string, description?: ?string, passing_score: int, max_attempts?: ?int, kind?: string, examiner_id?: ?int, questions: array<int, array<string, mixed>>} $attributes */
        $attributes = $request->validated();

        return QuizResource::make($save->handle($exam, $attributes));
    }

    /**
     * Вопросы можно и убрать целиком — вместе с попытками, как у теста урока.
     * Опубликованный экзамен без вопросов сам собой не живёт: публикация их
     * требует, так что убирают у черновика.
     */
    public function destroyQuiz(Exam $exam): Response
    {
        $exam->loadMissing('quiz')->quiz?->delete();

        return response()->noContent();
    }

    /**
     * Кого можно назначить — поиском. Тот же список, каким выбирают
     * проверяющего и согласующего (EmployedPeople): работающие сотрудники,
     * уволенным экзамен не назначают.
     */
    public function people(Request $request, EmployedPeople $people): AnonymousResourceCollection
    {
        return CoursePersonResource::collection($people->suggest($request->query('search')));
    }

    /**
     * Кому назначено — как есть, со сроками.
     */
    public function assignments(Exam $exam): JsonResponse
    {
        return response()->json([
            'data' => $exam->assignments()->with('user')->get()->map(static fn ($assignment): array => [
                'user' => $assignment->user === null ? null : [
                    'id' => $assignment->user->getKey(),
                    'name' => $assignment->user->name,
                ],
                'due_at' => $assignment->due_at,
                'assigned_at' => $assignment->created_at,
            ]),
        ]);
    }

    public function assign(AssignExamRequest $request, Exam $exam, AssignExam $assign): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{users?: list<int>, departments?: list<int>, groups?: list<int>, due_at?: ?string} $attributes */
        $attributes = $request->validated();

        $assignments = $assign->handle($exam, $attributes, $actor);

        return response()->json([
            'data' => $assignments->map(static fn ($assignment): array => [
                'user' => $assignment->user === null ? null : [
                    'id' => $assignment->user->getKey(),
                    'name' => $assignment->user->name,
                ],
                'due_at' => $assignment->due_at,
                'assigned_at' => $assignment->created_at,
            ]),
        ]);
    }

    /**
     * Как сдают: по строке на назначенного, несадившиеся — тоже строки.
     */
    public function results(Exam $exam, ExamResults $results): JsonResponse
    {
        return response()->json(['data' => $results->for($exam)]);
    }

    /**
     * Чужая попытка — ведущему: разбор с открытым ключом, как у автора теста.
     */
    public function attempt(Exam $exam, QuizAttempt $attempt, QuizReview $review): QuizAttemptResource
    {
        $quiz = $attempt->loadMissing('quiz', 'user')->quiz;

        if ($quiz === null
            || $quiz->quizzable_type !== $exam->getMorphClass()
            || (int) $quiz->quizzable_id !== (int) $exam->getKey()) {
            abort(404);
        }

        $attempt->setAttribute('review', $review->forAuthor($attempt));

        return QuizAttemptResource::make($attempt);
    }
}
