<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Exams;

use App\Actions\Exams\StartExamAttempt;
use App\Actions\Exams\SubmitExamAttempt;
use App\Enums\ExamStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SubmitQuizRequest;
use App\Http\Resources\Exams\AssignedExamResource;
use App\Http\Resources\Lms\QuizAttemptResource;
use App\Http\Resources\Lms\QuizResource;
use App\Models\Exam;
use App\Models\ExamAssignment;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\Exams\ExamResults;
use App\Support\Lms\QuizReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Экзамены глазами сдающего.
 *
 * Права на маршрутах нет намеренно: доступ даёт назначение, как у очереди
 * аттестаций, — отбор везде идёт по вошедшему, и чужой экзамен так не открыть.
 * Черновиков сдающий не видит вовсе: назначить черновик нельзя было бы и
 * показать, а снятый в черновик после назначения просто пропадает из списка до
 * новой публикации.
 */
final class ExamController extends Controller
{
    public function __construct(private readonly ExamResults $results) {}

    /**
     * Значок на рельсе: сколько экзаменов ждут сдачи и назначали ли вообще.
     *
     * Второе — отдельным полем и по той же причине, что у аттестаций: раздел
     * держится на назначении, а не на числе ждущих, иначе вкладка пропадала бы,
     * едва человек сдал последний экзамен, — а с ней и путь к результатам.
     */
    public function pendingCount(Request $request): JsonResponse
    {
        /** @var User $sitter */
        $sitter = $request->user();

        $assigned = ExamAssignment::query()
            ->where('user_id', $sitter->getKey())
            ->whereHas('exam', fn ($exam) => $exam->where('status', '!=', ExamStatus::Draft));

        return response()->json(['data' => [
            'pending' => (clone $assigned)
                ->whereDoesntHave('exam.quiz.attempts', fn ($attempts) => $attempts
                    ->where('user_id', $sitter->getKey())
                    ->where('passed', true))
                ->count(),
            'has_exams' => $assigned->exists(),
        ]]);
    }

    /**
     * Мои экзамены: что назначено, к какому сроку и как идут дела.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $sitter */
        $sitter = $request->user();

        $assignments = ExamAssignment::query()
            ->where('user_id', $sitter->getKey())
            ->whereHas('exam', fn ($exam) => $exam->where('status', '!=', ExamStatus::Draft))
            ->with(['exam.quiz.examiner'])
            ->get()
            ->each(function (ExamAssignment $assignment) use ($sitter): void {
                $assignment->setAttribute('summary', $this->results->summaryFor(
                    $assignment->exam?->quiz,
                    $sitter,
                    $assignment->due_at !== null && $assignment->due_at->isPast(),
                ));
            })
            // Несданное и срочное — сверху, сданное — вниз: список зовёт
            // работать, а не любоваться прошлым.
            ->sortBy([
                fn (ExamAssignment $a, ExamAssignment $b) => ($a->summary['passed'] <=> $b->summary['passed']),
                fn (ExamAssignment $a, ExamAssignment $b) => ($a->due_at?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->due_at?->getTimestamp() ?? PHP_INT_MAX),
            ])
            ->values();

        return AssignedExamResource::collection($assignments);
    }

    /**
     * Шапка экзамена перед началом: без вопросов — они приезжают с «Начать»,
     * иначе таймер мерил бы скорость набора заранее прочитанного.
     */
    public function show(Request $request, Exam $exam): AssignedExamResource
    {
        /** @var User $sitter */
        $sitter = $request->user();

        $assignment = $this->assignmentOf($exam, $sitter);

        $exam->loadMissing('quiz.examiner');
        $assignment->setRelation('exam', $exam);
        $assignment->setAttribute('summary', $this->results->summaryFor(
            $exam->quiz,
            $sitter,
            $assignment->due_at !== null && $assignment->due_at->isPast(),
        ));

        return AssignedExamResource::make($assignment);
    }

    /**
     * Начать попытку: с этой минуты тикает таймер, и вопросы можно отдать.
     */
    public function start(Request $request, Exam $exam, StartExamAttempt $start): JsonResponse
    {
        /** @var User $sitter */
        $sitter = $request->user();

        $this->ensureVisible($exam);

        $assignment = $start->handle($exam, $sitter);

        $quiz = $exam->loadMissing('quiz.questions.options', 'quiz.examiner')->quiz;

        // Перемешанный порядок не хранится: у каждой выдачи он свой, и этого
        // достаточно — сосед по столу видит другой первый вопрос.
        if ($exam->shuffle_questions) {
            $quiz->setRelation('questions', $quiz->questions->shuffle()->values());
        }

        return response()->json([
            'window' => [
                'started_at' => $assignment->started_at,
                'expires_at' => $assignment->expires_at,
            ],
            'quiz' => QuizResource::make($quiz),
        ]);
    }

    /**
     * Сдать работу. Оценка та же, что у тестов уроков; экзаменационное здесь —
     * окно и таймер, см. SubmitExamAttempt.
     */
    public function submit(SubmitQuizRequest $request, Exam $exam, SubmitExamAttempt $submit, QuizReview $review): QuizAttemptResource
    {
        /** @var User $sitter */
        $sitter = $request->user();

        $this->ensureVisible($exam);

        $attempt = $submit->handle($exam, $sitter, $request->answers());

        $attempt->setAttribute('review', $review->of($attempt, $sitter));

        return QuizAttemptResource::make($attempt);
    }

    /**
     * Разбор своей попытки: что выбрал и где ошибся.
     */
    public function attempt(Request $request, Exam $exam, QuizAttempt $attempt, QuizReview $review): QuizAttemptResource
    {
        /** @var User $sitter */
        $sitter = $request->user();

        // 404, а не 403: чужая попытка — не то, о существовании чего сообщают.
        if ($attempt->user_id !== $sitter->getKey() || ! $this->belongsToExam($attempt, $exam)) {
            abort(404);
        }

        $attempt->setAttribute('review', $review->of($attempt, $sitter));

        return QuizAttemptResource::make($attempt);
    }

    private function assignmentOf(Exam $exam, User $sitter): ExamAssignment
    {
        $this->ensureVisible($exam);

        $assignment = $exam->assignments()->where('user_id', $sitter->getKey())->first();

        if ($assignment === null) {
            abort(404);
        }

        return $assignment;
    }

    /** Черновик для сдающего не существует — 404, как и неназначенный экзамен. */
    private function ensureVisible(Exam $exam): void
    {
        if ($exam->status === ExamStatus::Draft) {
            abort(404);
        }
    }

    private function belongsToExam(QuizAttempt $attempt, Exam $exam): bool
    {
        $quiz = $attempt->loadMissing('quiz')->quiz;

        return $quiz !== null
            && $quiz->quizzable_type === $exam->getMorphClass()
            && (int) $quiz->quizzable_id === (int) $exam->getKey();
    }
}
