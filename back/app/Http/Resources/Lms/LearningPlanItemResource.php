<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\LearningPlanItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Шаг плана обучения — курс или регламент.
 *
 * Отдаётся плоско: вид, название, адрес и прогресс. Двух вложенных объектов на
 * выбор здесь нет намеренно — экран плана рисует список одинаковых строк, и
 * разбирать на клиенте, в каком из двух полей на этот раз лежит название,
 * значит писать это разбирательство в каждом месте, где план показывают.
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
        $item = $this->plannable;

        return [
            'id' => $this->id,
            'position' => $this->position,
            'assigned_at' => $this->created_at?->toIso8601String(),
            'assigned_by' => $this->whenLoaded('assignedBy', fn (): ?array => $this->assignedBy === null ? null : [
                'id' => $this->assignedBy->id,
                'name' => $this->assignedBy->name,
            ]),

            // «course» или «regulation» — короткое имя из карты, а не класс.
            'kind' => $this->plannable_type,
            'item_id' => $this->plannable_id,
            'title' => $item?->title,
            'slug' => $item?->slug,
            'summary' => $item?->summary,

            // Проверка при документе и то, как этот человек её прошёл. Null —
            // проверки нет; у курса её и не бывает, там тест висит на уроке.
            'quiz' => $this->quiz,

            // Прогресс сотрудника по этому шагу. Проставляет контроллер: он
            // один знает, чей план показывают, — свой или чужой.
            'progress' => $this->progress_percentage,
            'is_started' => $this->is_started,
            'is_completed' => $this->is_completed,

            // Когда шаг был пройден: у курса это день, когда он был закрыт
            // целиком, у документа — отметка об ознакомлении.
            'completed_at' => $this->completed_at,

            // Только для того, кто план составляет: назначить материал,
            // которого сотрудник не увидит, можно по недосмотру, и сказать об
            // этом надо на том же экране, а не оставлять шаг молча пропадать.
            'is_visible_to_learner' => $this->when(
                $this->is_visible_to_learner !== null,
                fn (): bool => (bool) $this->is_visible_to_learner,
            ),
        ];
    }
}
