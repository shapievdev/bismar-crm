<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\ApprovalStatus;
use App\Exceptions\ConflictException;
use App\Jobs\SendPush;
use App\Models\Contracts\Approvable;
use App\Models\MaterialReview;
use App\Models\MaterialReviewDecision;
use App\Models\User;
use App\Support\Push\PushMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ответ согласующего: согласовать или вернуть на исправление.
 *
 * Два исхода, и они не равны. **Согласие — последний голос, а не свой
 * собственный:** материал выходит, когда согласовали все (требование
 * пользователя), поэтому решение одного человека либо закрывает круг публикацией,
 * либо просто убавляет ожидание. **Возврат заканчивает круг сразу:** ждать
 * остальных, когда текст уже признан негодным, значит просить их согласовать то,
 * что автор всё равно переписывает.
 *
 * Публикация и закрытие круга — в одной транзакции: материал, вышедший при
 * незакрытом круге, ждал бы ответа от людей, которым уже нечего решать.
 */
final readonly class DecideMaterialApproval
{
    public function approve(MaterialReview $review, User $person, ?string $comment = null): MaterialReview
    {
        $decision = $this->answerOf($review, $person);

        $published = DB::transaction(function () use ($review, $decision, $comment): bool {
            $this->record($decision, ApprovalStatus::Approved, $comment);

            // Перечитываем ответы: только что записанный нужен подсчёту, а в
            // прочитанном вместе с кругом его ещё нет.
            $review->load('decisions');

            if (! $review->isUnanimous()) {
                return false;
            }

            $review->update([
                'status' => ApprovalStatus::Approved,
                'closed_at' => now(),
            ]);

            $material = $review->reviewable;

            // Уже опубликованный материал согласовали после правки: выкладывать
            // его второй раз нечего, круг просто закрывается.
            if ($material instanceof Approvable && ! $material->isPublished()) {
                $material->publishAfterApproval();
            }

            return true;
        });

        if ($published) {
            $this->tellTheAuthor($review, approved: true);
        }

        return $review->load(['decisions.user:id,last_name,first_name,middle_name']);
    }

    /**
     * Вернуть автору с причиной.
     *
     * Причина обязательна: возврат без неё не говорит автору, что делать, и
     * превращает согласование в молчаливый отказ. Проверяется и здесь, а не
     * только в разборе запроса, — действие зовут и сидеры, и тесты.
     */
    public function returnForRevision(MaterialReview $review, User $person, string $comment): MaterialReview
    {
        $reason = trim($comment);

        if ($reason === '') {
            throw new ConflictException('Напишите, что исправить: без причины материал не возвращают.');
        }

        $decision = $this->answerOf($review, $person);

        DB::transaction(function () use ($review, $decision, $reason): void {
            $this->record($decision, ApprovalStatus::Returned, $reason);

            $review->update([
                'status' => ApprovalStatus::Returned,
                'closed_at' => now(),
            ]);
        });

        $this->tellTheAuthor($review, approved: false, reason: $reason);

        return $review->load(['decisions.user:id,last_name,first_name,middle_name']);
    }

    private function record(MaterialReviewDecision $decision, ApprovalStatus $status, ?string $comment): void
    {
        $decision->update([
            'status' => $status,
            'comment' => $comment === null || trim($comment) === '' ? null : trim($comment),
            'decided_at' => now(),
        ]);
    }

    /**
     * Строка этого человека в этом круге — если его звали и он ещё не ответил.
     *
     * Все три отказа — 409 с объяснением: они про состояние круга, а не про
     * права, и человеку важно знать, почему кнопка ничего не сделала.
     */
    private function answerOf(MaterialReview $review, User $person): MaterialReviewDecision
    {
        if (! $review->isOpen()) {
            throw new ConflictException('Согласование уже закончилось.');
        }

        $decision = $review->decisions()->where('user_id', $person->getKey())->first();

        if ($decision === null) {
            throw new ConflictException('Вас не просили согласовать этот материал.');
        }

        if (! $decision->isPending()) {
            throw new ConflictException('Вы уже ответили на эту отправку.');
        }

        return $decision;
    }

    /**
     * Сказать тому, кто отправлял.
     *
     * Ему, а не автору материала: отправить может и редактор, которого в
     * материал впустили, и ответа ждёт именно он.
     */
    private function tellTheAuthor(MaterialReview $review, bool $approved, ?string $reason = null): void
    {
        $material = $review->reviewable;

        if (! $material instanceof Approvable || ! $material instanceof Model) {
            return;
        }

        $title = PushMessage::shorten($material->approvalTitle(), 60);

        SendPush::dispatch([(int) $review->requested_by], new PushMessage(
            title: $approved ? 'Материал согласован' : 'Вернули на исправление',
            body: $approved
                ? sprintf('«%s» согласовали все — материал опубликован.', $title)
                : sprintf('«%s»: %s', $title, PushMessage::shorten($reason, 90)),
            url: $material->approvalPath(),
            tag: 'approval-'.$material->getMorphClass().'-'.$material->getKey(),
        ));
    }
}
