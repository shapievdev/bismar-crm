<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Enums\ApprovalStatus;
use App\Models\Contracts\Approvable;
use App\Models\MaterialReview;
use App\Models\MaterialReviewDecision;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Круг согласования — как его видят обе стороны.
 *
 * Автору важно, кто ещё не ответил и что именно не понравилось; согласующему —
 * ждут ли ответа от него. Поэтому «моё» считается по вошедшему, а не
 * присылается клиентом: подставив чужой номер, чужую кнопку не получить.
 *
 * @mixin MaterialReview
 */
final class MaterialReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reader = $request->user();

        return [
            'id' => $this->id,
            'round' => $this->round,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_open' => $this->resource->isOpen(),

            'requested_by' => $this->whenLoaded('requester', fn (): ?string => $this->requester?->name),
            'submitted_at' => $this->created_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),

            'decisions' => $this->whenLoaded(
                'decisions',
                fn (): array => $this->decisions
                    ->map(fn (MaterialReviewDecision $decision): array => [
                        'id' => $decision->id,
                        'user' => [
                            'id' => $decision->user_id,
                            'name' => $decision->relationLoaded('user') ? $decision->user?->name : null,
                        ],
                        'status' => $decision->status->value,
                        'status_label' => $decision->status->label(),
                        'comment' => $decision->comment,
                        'decided_at' => $decision->decided_at?->toIso8601String(),
                    ])->all(),
            ),

            // Ждут ли ответа именно от того, кто смотрит: по этому экран рисует
            // кнопки «Согласовать» и «Вернуть», а не по наличию круга.
            'awaits_me' => $reader instanceof User
                && $this->resource->isOpen()
                && $this->resource->decisionOf($reader)?->status === ApprovalStatus::Pending,

            // Причина последнего возврата — то, ради чего автор сюда и смотрит.
            'returned_reason' => $this->returnedReason(),

            // Материал приезжает вместе с кругом только в очереди согласующего:
            // на странице материала он и так открыт.
            'material' => $this->when(
                $this->relationLoaded('reviewable') && $this->reviewable instanceof Approvable,
                fn (): array => [
                    'title' => $this->reviewable->approvalTitle(),
                    'label' => $this->reviewable->approvalLabel(),
                    'path' => $this->reviewable->approvalPath(),
                    'is_published' => $this->reviewable->isPublished(),
                ],
            ),
        ];
    }

    /**
     * Чем объяснили возврат.
     *
     * Возврат заканчивает круг, поэтому причина в нём одна: та, что написал
     * вернувший. Ищется среди прочитанных ответов — без них круг о причине не
     * спрашивают.
     */
    private function returnedReason(): ?string
    {
        if (! $this->resource->relationLoaded('decisions')) {
            return null;
        }

        return $this->decisions
            ->firstWhere('status', ApprovalStatus::Returned)
            ?->comment;
    }
}
