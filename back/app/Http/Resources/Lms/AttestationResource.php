<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\Lesson;
use App\Models\QuizAttempt;
use App\Models\Regulation;
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
            'material' => $this->material($owner),

            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            'comment' => $this->review_comment,
        ];
    }

    /**
     * Материал, к которому привязан тест, и адрес его страницы.
     *
     * @return array<string, mixed>|null
     */
    private function material(mixed $owner): ?array
    {
        if ($owner instanceof Lesson) {
            $course = $owner->loadMissing('module.course')->module?->course;

            return [
                'kind' => 'lesson',
                'title' => $owner->title,
                'course' => $course?->title,
                'url' => $course === null ? null : "/lms/{$course->slug}/lessons/{$owner->getKey()}",
            ];
        }

        if ($owner instanceof Regulation) {
            return [
                'kind' => 'document',
                'title' => $owner->title,
                'course' => null,
                'url' => "/lms/documents/{$owner->slug}",
            ];
        }

        return null;
    }
}
