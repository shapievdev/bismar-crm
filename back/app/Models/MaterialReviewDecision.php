<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ответ одного согласующего в круге.
 *
 * Строка заводится вместе с кругом и ждёт ответа: «ещё не ответил» — такое же
 * состояние, как «согласовал», и видно оно обеим сторонам. Без строки автор не
 * знал бы, кого он позвал, а согласующий — что его ждут.
 *
 * Причина обязательна при возврате и необязательна при согласовании: возврат без
 * причины не говорит автору ничего, а «согласен» говорит само за себя.
 */
#[Fillable(['review_id', 'user_id', 'status', 'comment', 'decided_at'])]
class MaterialReviewDecision extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MaterialReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(MaterialReview::class, 'review_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }
}
