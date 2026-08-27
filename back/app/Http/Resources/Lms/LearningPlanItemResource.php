<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\LearningPlanItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Шаг плана обучения.
 *
 * @mixin LearningPlanItem
 */
final class LearningPlanItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'assigned_at' => $this->created_at?->toIso8601String(),
            'assigned_by' => $this->whenLoaded('assignedBy', fn (): ?array => $this->assignedBy === null ? null : [
                'id' => $this->assignedBy->id,
                'name' => $this->assignedBy->name,
            ]),
            'course' => CourseResource::make($this->whenLoaded('course')),

            // Прогресс сотрудника по этому шагу. Проставляет контроллер: он
            // один знает, чей план показывают, — свой или чужой.
            'progress' => $this->progress_percentage,
            'is_started' => $this->is_started,
            'is_completed' => $this->is_completed,

            // Только для того, кто план составляет: назначить курс, которого
            // сотрудник не увидит, можно по недосмотру, и сказать об этом надо
            // на том же экране, а не оставлять шаг молча пропадать у него.
            'is_visible_to_learner' => $this->when(
                $this->is_visible_to_learner !== null,
                fn (): bool => (bool) $this->is_visible_to_learner,
            ),
        ];
    }
}
