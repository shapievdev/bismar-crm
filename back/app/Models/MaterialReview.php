<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Круг согласования материала.
 *
 * Один круг — одна отправка: список согласующих, их ответы и итог. Вернули с
 * причиной — круг закончился, исправленный материал уходит новым кругом, и
 * прежние ответы в нём не участвуют (решение пользователя 2026-09-30): текст
 * изменился, а «согласен» относилось к прежнему.
 *
 * Открытый круг у материала один — держится частичным уникальным индексом, см.
 * миграцию. Правила «кому что можно» живут не здесь, а в
 * App\Support\Lms\MaterialApprovals: спрашивают их с четырёх концов — политика,
 * доступ, очередь и значок, — и держать ответы врозь значило бы однажды
 * расширить один и забыть остальные.
 */
#[Fillable(['reviewable_type', 'reviewable_id', 'round', 'status', 'requested_by', 'closed_at'])]
class MaterialReview extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'round' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Материал, который согласуют: документ, справочник или курс.
     *
     * @return MorphTo<Model, $this>
     */
    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasMany<MaterialReviewDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(MaterialReviewDecision::class, 'review_id')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    /**
     * Все ли согласовали.
     *
     * Единогласно и только: «после того как все согласуют» — требование
     * пользователя, и большинства здесь не бывает. Считается по уже прочитанным
     * ответам, если их загрузили вместе с кругом.
     */
    public function isUnanimous(): bool
    {
        return $this->loadedDecisions()->every(
            fn (MaterialReviewDecision $decision): bool => $decision->status === ApprovalStatus::Approved,
        );
    }

    /** Кто ещё не ответил. */
    public function awaiting(): Collection
    {
        return $this->loadedDecisions()
            ->filter(fn (MaterialReviewDecision $decision): bool => $decision->status === ApprovalStatus::Pending)
            ->values();
    }

    /** Ответ этого человека в круге, если его сюда позвали. */
    public function decisionOf(User $person): ?MaterialReviewDecision
    {
        return $this->loadedDecisions()
            ->firstWhere('user_id', $person->getKey());
    }

    /**
     * Ответы — уже прочитанные, если их загрузили вместе с кругом.
     *
     * @return Collection<int, MaterialReviewDecision>
     */
    private function loadedDecisions(): Collection
    {
        return $this->relationLoaded('decisions') ? $this->decisions : $this->decisions()->get();
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending);
    }
}
