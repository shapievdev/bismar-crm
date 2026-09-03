<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QuizAttempt
 */
final class QuizAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'score' => $this->score,
            'passed' => $this->passed,
            'completed_at' => $this->completed_at?->toIso8601String(),

            /*
             * Что с работой стало. У обычного теста это всегда «оценено
             * приложением» и экрану ни о чём не говорит; у аттестации — то
             * единственное, ради чего человек возвращается на страницу: дошла
             * ли работа, ответили ли, а если не зачли — то почему.
             */
            'review_status' => $this->review_status->value,
            'review_status_label' => $this->review_status->label(),
            'review_comment' => $this->review_comment,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),

            // Разбор — что выбрано и где ошибка. Прикладывается контроллером
            // там, где попытку показывают одну; в списке прошлых попыток его
            // нет, чтобы не тянуть весь тест ради строки с процентом.
            'review' => $this->review,
        ];
    }
}
