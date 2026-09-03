<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\AttestationStatus;
use App\Exceptions\ConflictException;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\QuizAttempt;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Вердикт человека по сданной работе.
 *
 * Здесь заканчивается то, ради чего заведена аттестация: приложение довело
 * работу до проверяющего, а решение — его. Зачёт делает ровно то же, что
 * сделала бы сдача обычного теста: урок засчитывается пройденным, документ —
 * прочитанным. Разница только в том, кто это решил.
 *
 * Отказ ничего не отменяет и ничего не портит: попытка остаётся в истории с
 * комментарием, а сдать заново можно, пока есть попытки. Комментарий при отказе
 * обязателен — «не зачтено» без объяснения не учит ничему и заставляет
 * переспрашивать в мессенджере.
 */
final readonly class ReviewAttestation
{
    public function __construct(
        private CompleteLesson $completeLesson,
        private AcknowledgeRegulation $acknowledgeRegulation,
    ) {}

    /**
     * @throws ConflictException
     */
    public function handle(QuizAttempt $attempt, User $examiner, bool $isAccepted, ?string $comment): QuizAttempt
    {
        if (! $attempt->isAwaitingReview()) {
            throw new ConflictException('Эту работу уже проверили.');
        }

        return DB::transaction(function () use ($attempt, $examiner, $isAccepted, $comment): QuizAttempt {
            $attempt->fill([
                'review_status' => $isAccepted ? AttestationStatus::Passed : AttestationStatus::Failed,
                'passed' => $isAccepted,
                'reviewed_by' => $examiner->getKey(),
                'reviewed_at' => now(),
                'review_comment' => $comment,
            ])->save();

            if ($isAccepted) {
                $this->rewardFor($attempt);
            }

            return $attempt;
        });
    }

    /**
     * Что означает зачёт — решает владелец теста, ровно как при обычной сдаче.
     *
     * Урок засчитывается той же дорогой, что и всегда (CompleteLesson знает про
     * порядок уроков и про запись на курс), документ — ознакомлением. Записи на
     * курс у сдававшего может и не быть: работу сдают и по назначенному плану,
     * и по своей воле — тогда зачитывать нечего, и это не ошибка.
     */
    private function rewardFor(QuizAttempt $attempt): void
    {
        $owner = $attempt->loadMissing('quiz.quizzable', 'user')->quiz?->quizzable;
        $learner = $attempt->user;

        if ($learner === null) {
            return;
        }

        if ($owner instanceof Lesson) {
            $enrollment = Enrollment::query()
                ->where('user_id', $learner->getKey())
                ->where('course_id', $owner->loadMissing('module.course')->module?->course?->getKey())
                ->first();

            // Непройденные предыдущие уроки зачёт не отменяют: он записан, и
            // урок закроется, как только очередь дойдёт до него, — та же
            // оговорка, что и при обычной сдаче, см. GradeQuizAttempt.
            if ($enrollment !== null && $this->completeLesson->blockedBy($enrollment, $owner) === null) {
                $this->completeLesson->handle($enrollment, $owner);
            }

            return;
        }

        if ($owner instanceof Regulation) {
            $this->acknowledgeRegulation->handle($owner, $learner);
        }
    }
}
