<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Enrollment
 */
final class EnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrolled_at' => $this->enrolled_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_completed' => $this->isCompleted(),
            // Set by the controller; additional() would merge into the
            // response root rather than this item.
            'progress' => $this->progress_percentage,
            'completed_lesson_ids' => $this->whenLoaded(
                'completions',
                fn () => $this->completions->pluck('lesson_id')->values(),
            ),
            'course' => CourseResource::make($this->whenLoaded('course')),
            'user' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
        ];
    }
}
