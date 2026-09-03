<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\AttestationStatus;
use App\Exceptions\ConflictException;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Lms\AnswerSimilarity;
use App\Support\Lms\QuestionTable;
use Illuminate\Support\Facades\DB;

final readonly class GradeQuizAttempt
{
    public function __construct(
        private CompleteLesson $completeLesson,
        private AcknowledgeRegulation $acknowledgeRegulation,
        private AnswerSimilarity $similarity,
    ) {}

    /**
     * Grades a submission, records the attempt, and — if the learner passed —
     * does whatever passing means for the thing the quiz hangs off.
     *
     * @param  array<int, list<int>|list<list<string>>|string>  $answers  Номер вопроса =>
     *                                                                    выбранные варианты, написанный ответ или строки таблицы.
     *
     * @throws ConflictException
     */
    public function handle(Quiz $quiz, User $learner, array $answers, ?Enrollment $enrollment): QuizAttempt
    {
        $quiz->loadMissing('questions.options');

        if ($quiz->questions->isEmpty()) {
            throw new ConflictException('В тесте пока нет вопросов.');
        }

        if (! $quiz->hasAttemptsLeft($learner)) {
            throw new ConflictException('Попытки закончились.');
        }

        // Одна работа на проверке за раз: вторая, отправленная в ожидании
        // вердикта, только удвоит очередь проверяющему, а сотруднику не
        // ответит быстрее.
        if ($this->isAwaitingReview($quiz, $learner)) {
            throw new ConflictException('Прошлая работа ещё на проверке — дождитесь ответа.');
        }

        $totalPoints = $quiz->totalPoints();
        $earnedPoints = 0;
        $scores = [];

        foreach ($quiz->questions as $question) {
            $given = $answers[$question->id] ?? null;

            $verdict = match (true) {
                $question->type->isWritten() => $this->judgeWritten($question, is_string($given) ? $given : null),
                $question->type->isTable() => $this->judgeTable($question, is_array($given) ? $given : []),
                default => ['is_accepted' => $this->isAnsweredCorrectly($question, is_array($given) ? $given : [])],
            };

            if ($verdict['is_accepted']) {
                $earnedPoints += $question->points;
            }

            // Разбор оценки по каждому вопросу: у письменного — чем измерена
            // схожесть и какая вышла. Без этого «не сдано» было бы приговором
            // без объяснения.
            $scores[$question->id] = [
                'points' => $verdict['is_accepted'] ? $question->points : 0,
                ...array_diff_key($verdict, ['is_accepted' => null]),
            ];
        }

        $score = $totalPoints > 0 ? (int) round($earnedPoints / $totalPoints * 100) : 0;

        /*
         * У аттестации приложение баллы считает, но приговора не выносит.
         *
         * Считает — потому что проверяющему полезно видеть, что сошлось само:
         * выбор вариантов и близость письменного ответа к эталону. Не выносит —
         * потому что главное в такой работе таблица, а верны ли в ней числа,
         * приложение не знает. Сказать «сдано» по заполненности значило бы
         * подписаться под тем, чего никто не читал.
         */
        $isAttestation = $quiz->isAttestation();
        $passed = ! $isAttestation && $score >= $quiz->passing_score;

        return DB::transaction(function () use ($quiz, $learner, $answers, $scores, $score, $passed, $isAttestation, $enrollment): QuizAttempt {
            $attempt = QuizAttempt::create([
                'quiz_id' => $quiz->getKey(),
                'user_id' => $learner->getKey(),
                'score' => $score,
                'passed' => $passed,
                'answers' => $answers,
                'scores' => $scores,
                'completed_at' => now(),
                'review_status' => $isAttestation ? AttestationStatus::Pending : AttestationStatus::Auto,
            ]);

            if ($passed) {
                $this->rewardFor($quiz, $learner, $enrollment);
            }

            return $attempt;
        });
    }

    /**
     * Ждёт ли предыдущая работа этого человека вердикта.
     *
     * У обычного теста такого состояния не бывает вовсе, поэтому и спрашивать
     * базу незачем.
     */
    private function isAwaitingReview(Quiz $quiz, User $learner): bool
    {
        if (! $quiz->isAttestation()) {
            return false;
        }

        return $quiz->attempts()
            ->where('user_id', $learner->getKey())
            ->where('review_status', AttestationStatus::Pending)
            ->exists();
    }

    /**
     * Что означает сдача — решает владелец теста.
     *
     * У урока это прохождение урока, и только если до него дошли по порядку:
     * непройденные предыдущие попытку не отменяют — она записана, и урок
     * зачтётся, как только очередь дойдёт до него.
     *
     * У документа это ознакомление: сдал — значит прочитал и понял, и другой
     * отметки у документа с проверкой нет (решение пользователя 2026-09-01).
     */
    private function rewardFor(Quiz $quiz, User $learner, ?Enrollment $enrollment): void
    {
        $owner = $quiz->quizzable;

        if ($owner instanceof Regulation) {
            $this->acknowledgeRegulation->handle($owner, $learner);

            return;
        }

        if ($owner instanceof Lesson
            && $enrollment !== null
            && $this->completeLesson->blockedBy($enrollment, $owner) === null) {
            $this->completeLesson->handle($enrollment, $owner);
        }
    }

    /**
     * Письменный ответ проверяет ИИ: сравнивает написанное с эталоном автора по
     * смыслу и зачитывает вопрос, если схожесть выше порога (решение
     * пользователя 2026-09-02). Частичного зачёта нет — как и у выбора
     * варианта: вопрос либо взят, либо нет.
     *
     * @return array{is_accepted: bool, similarity: float, threshold: float, measured_by: string}
     */
    private function judgeWritten(QuizQuestion $question, ?string $given): array
    {
        return $this->similarity->of($given, $question->expected_answer);
    }

    /**
     * Таблица зачитывается по заполненности: верны ли в ней числа, приложение
     * знать не может — это работа, которую читает человек. Правило целиком
     * лежит в QuestionTable.
     *
     * @param  array<int|string, mixed>  $given
     * @return array{is_accepted: bool, filled_cells: int, required_cells: int}
     */
    private function judgeTable(QuizQuestion $question, array $given): array
    {
        /** @var array<string, mixed>|null $definition */
        $definition = $question->table_definition;

        if ($definition === null) {
            return ['is_accepted' => false, 'filled_cells' => 0, 'required_cells' => 0];
        }

        /** @var list<list<string>> $rows */
        $rows = array_values(array_filter($given, is_array(...)));

        return QuestionTable::judge(QuestionTable::normalise($definition), $rows);
    }

    /**
     * A question is right only when the chosen options match the correct set
     * exactly — partial credit would let a learner tick every box and pass.
     *
     * @param  list<int>  $selectedOptionIds
     */
    private function isAnsweredCorrectly(QuizQuestion $question, array $selectedOptionIds): bool
    {
        $correct = $question->correctOptionIds()->map(intval(...))->sort()->values()->all();
        $selected = collect($selectedOptionIds)->map(intval(...))->unique()->sort()->values()->all();

        return $correct === $selected && $correct !== [];
    }
}
