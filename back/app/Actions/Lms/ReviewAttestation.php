<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\AttestationStatus;
use App\Exceptions\ConflictException;
use App\Jobs\SendPush;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\Lms\MaterialLink;
use App\Support\Push\PushMessage;
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
    public function __construct(private CreditAttempt $credit) {}

    /**
     * @throws ConflictException
     */
    public function handle(QuizAttempt $attempt, User $examiner, bool $isAccepted, ?string $comment): QuizAttempt
    {
        if (! $attempt->isAwaitingReview()) {
            throw new ConflictException('Эту работу уже проверили.');
        }

        $reviewed = DB::transaction(function () use ($attempt, $examiner, $isAccepted, $comment): QuizAttempt {
            $attempt->fill([
                'review_status' => $isAccepted ? AttestationStatus::Passed : AttestationStatus::Failed,
                'passed' => $isAccepted,
                'reviewed_by' => $examiner->getKey(),
                'reviewed_at' => now(),
                'review_comment' => $comment,
            ])->save();

            if ($isAccepted) {
                // Что означает зачёт, решает материал, при котором стоит тест,
                // — и решает одинаково, кто бы зачёт ни поставил. См.
                // CreditAttempt.
                $this->credit->handle($attempt);
            }

            return $attempt;
        });

        $this->tellLearner($reviewed, $examiner, $isAccepted, $comment);

        return $reviewed;
    }

    /**
     * Говорит сдавшему, чем кончилось.
     *
     * Ожидание здесь — не минуты, а дни, и всё это время человек не знает,
     * зачли ему урок или придётся переделывать. Отказ без причины заставляет
     * переспрашивать в мессенджере, поэтому комментарий проверяющего едет
     * прямо в уведомлении: он и есть ответ на «что не так».
     *
     * Себе не сообщаем — проверяющий, разобравший собственную работу, о своём
     * решении уже знает.
     *
     * Имя уведомления — сама работа: вердикт по ней выносится один раз, и
     * заменять тут нечего.
     */
    private function tellLearner(QuizAttempt $attempt, User $examiner, bool $isAccepted, ?string $comment): void
    {
        $learner = $attempt->loadMissing('user')->user;

        if ($learner === null || $learner->is($examiner) || $learner->dismissed_at !== null) {
            return;
        }

        $material = MaterialLink::for($attempt->loadMissing('quiz.quizzable')->quiz?->quizzable);

        SendPush::dispatch([(int) $learner->getKey()], new PushMessage(
            title: $isAccepted ? 'Аттестация зачтена' : 'Аттестация не зачтена',
            body: PushMessage::shorten($this->verdict($material, $isAccepted, $comment)),
            // Ведём к материалу, а не в очередь: очередь — раздел проверяющего,
            // сдавшему в ней нечего делать. Некуда вести — остаётся план: там
            // видно, что осталось пройти.
            url: $material?->url ?? '/lms/plan',
            tag: sprintf('attestation-verdict-%d', $attempt->getKey()),
        ));
    }

    /**
     * Что человек прочтёт на экране телефона: по какой работе ответ и, если
     * проверяющий написал, почему.
     */
    private function verdict(?MaterialLink $material, bool $isAccepted, ?string $comment): string
    {
        $about = $material === null
            ? ($isAccepted ? 'Работа принята.' : 'Работу нужно переделать.')
            : sprintf(
                $isAccepted ? 'Работа принята: %s.' : 'Работу нужно переделать: %s.',
                $material->caption(),
            );

        $said = trim((string) $comment);

        return $said === '' ? $about : $about.' '.$said;
    }
}
