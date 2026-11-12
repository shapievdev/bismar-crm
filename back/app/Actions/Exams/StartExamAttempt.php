<?php

declare(strict_types=1);

namespace App\Actions\Exams;

use App\Exceptions\ConflictException;
use App\Models\Exam;
use App\Models\ExamAssignment;
use App\Models\Quiz;
use App\Models\User;
use App\Support\Exams\ExamSitting;
use Illuminate\Support\Facades\Date;

/**
 * Открывает попытку: с этой минуты тикает таймер, и вопросы можно показать.
 *
 * Вопросы до начала не выдаются намеренно — иначе таймер мерил бы не знание, а
 * скорость набора: прочитать всё заранее, начать и вписать готовое. Поэтому
 * экран до «Начать» показывает только шапку, а вопросы приезжают отсюда.
 *
 * Повторное «Начать» при живом окне продолжает ту же попытку, а не заводит
 * новую: обновление страницы — не решение сдавать заново, и наказывать за F5
 * потраченной попыткой нельзя.
 */
final readonly class StartExamAttempt
{
    public function __construct(private ExamSitting $sitting) {}

    /**
     * @throws ConflictException
     */
    public function handle(Exam $exam, User $sitter): ExamAssignment
    {
        $assignment = $this->assignmentOf($exam, $sitter);
        $quiz = $this->quizOf($exam);

        // Живое окно — та же попытка: отдать как есть, таймер продолжает идти.
        if ($assignment->hasOpenWindowAt(Date::now())) {
            return $assignment;
        }

        // Просроченное и не отправленное списывается прежде всех проверок:
        // потраченная попытка должна попасть в счёт до вопроса «остались ли».
        $this->sitting->writeOffExpiredWindow($assignment, $quiz);

        $reason = $exam->closedReasonAt(Date::now());

        if ($reason !== null) {
            throw new ConflictException($reason);
        }

        if ($this->sitting->isAwaitingReview($quiz, $sitter)) {
            throw new ConflictException('Прошлая работа ещё на проверке — дождитесь ответа.');
        }

        if (! $quiz->hasAttemptsLeft($sitter)) {
            throw new ConflictException('Попытки закончились.');
        }

        $now = Date::now();

        $assignment->fill([
            'started_at' => $now,
            'expires_at' => $exam->time_limit_minutes === null
                ? null
                : $now->addMinutes($exam->time_limit_minutes),
        ])->save();

        return $assignment;
    }

    /**
     * Сдают по назначению: экзамена нет в списке — нет и в ответе.
     *
     * 404, а не 403: о существовании экзамена, который человеку не назначали,
     * сообщать не стоит — как и о чужой попытке.
     *
     * @throws ConflictException
     */
    private function assignmentOf(Exam $exam, User $sitter): ExamAssignment
    {
        $assignment = $exam->assignments()->where('user_id', $sitter->getKey())->first();

        if ($assignment === null) {
            abort(404);
        }

        return $assignment;
    }

    /**
     * @throws ConflictException
     */
    private function quizOf(Exam $exam): Quiz
    {
        $quiz = $exam->loadMissing('quiz.questions.options')->quiz;

        // У опубликованного экзамена вопросы есть — за этим следит публикация.
        // Пусто бывает, если их стёрли после: честный отказ лучше пустой формы.
        if ($quiz === null || $quiz->questions->isEmpty()) {
            throw new ConflictException('В экзамене пока нет вопросов.');
        }

        return $quiz;
    }
}
