<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\QuizAttempt;
use App\Support\Lms\MaterialLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Строка в очереди проверяющего: кто, что и когда сдал.
 *
 * Разбора здесь нет намеренно — он тяжёлый, а очередь читают, чтобы выбрать, за
 * что взяться. Ответы приходят отдельным запросом, когда работу открывают.
 *
 * @mixin QuizAttempt
 */
final class AttestationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quiz = $this->whenLoaded('quiz');
        $owner = $this->quiz?->quizzable;

        return [
            'id' => $this->id,
            'status' => $this->review_status->value,
            'status_label' => $this->review_status->label(),

            // Счёт, посчитанный приложением: он ничего не решает, но говорит
            // проверяющему, что сошлось само, — с чего начать чтение.
            'score' => $this->score,
            'completed_at' => $this->completed_at?->toIso8601String(),

            'learner' => [
                'id' => $this->user?->getKey(),
                'name' => $this->user?->name,
            ],

            'quiz' => [
                'id' => $quiz?->getKey(),
                'title' => $quiz?->title,
            ],

            // Куда работа относится: без этого в очереди из двадцати строк
            // непонятно, о каком уроке речь.
            'material' => MaterialLink::for($owner)?->toArray(),

            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            'comment' => $this->review_comment,
        ];
    }
}
