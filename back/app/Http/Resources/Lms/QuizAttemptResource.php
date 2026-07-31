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
        ];
    }
}
