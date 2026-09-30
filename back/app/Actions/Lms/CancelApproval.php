<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\ApprovalStatus;
use App\Jobs\SendPush;
use App\Models\Contracts\Approvable;
use App\Models\MaterialReview;
use App\Models\User;
use App\Support\Lms\MaterialApprovals;
use App\Support\Push\PushMessage;
use Illuminate\Database\Eloquent\Model;

/**
 * Закрыть идущий круг, не дождавшись ответов.
 *
 * Бывает от двух причин, и обе — решение автора: он **выложил материал сам**, не
 * дожидаясь согласования (право, которое пользователь оговорил прямо), либо
 * **отозвал отправку** — передумал, пока никто не ответил.
 *
 * Круг при этом не удаляется: он остаётся в истории отмененным, и по нему видно,
 * что людей звали и ответить не дали. Уведомление им обязательно — иначе они
 * пришли бы решать то, чего уже нет.
 *
 * Зовут отсюда и сохранение материала: публикация руками закрывает согласование
 * молча для автора, но не для позванных — см. RegulationController.
 */
final readonly class CancelApproval
{
    public function __construct(private MaterialApprovals $approvals) {}

    /**
     * Закрыть круг, если он идёт. Возвращает закрытый круг или null, если
     * закрывать было нечего: звать это можно из любого сохранения, не спрашивая
     * заранее.
     */
    public function handle(Model&Approvable $material, User $by, bool $published = false): ?MaterialReview
    {
        $review = $this->approvals->open($material);

        if ($review === null) {
            return null;
        }

        $review->update([
            'status' => ApprovalStatus::Cancelled,
            'closed_at' => now(),
        ]);

        $this->tell($review, $material, $by, $published);

        return $review;
    }

    private function tell(MaterialReview $review, Model&Approvable $material, User $by, bool $published): void
    {
        $waiting = $review->decisions()
            ->where('status', ApprovalStatus::Pending)
            ->pluck('user_id')
            ->map(intval(...))
            ->all();

        if ($waiting === []) {
            return;
        }

        SendPush::dispatch($waiting, new PushMessage(
            title: 'Согласование отменено',
            body: sprintf(
                '«%s»: %s',
                PushMessage::shorten($material->approvalTitle(), 60),
                $published
                    ? sprintf('%s выложил материал, не дожидаясь согласования.', $by->name)
                    : sprintf('%s отозвал отправку.', $by->name),
            ),
            url: $material->approvalPath(),
            tag: 'approval-'.$material->getMorphClass().'-'.$material->getKey(),
        ));
    }
}
