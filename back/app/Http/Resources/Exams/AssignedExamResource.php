<?php

declare(strict_types=1);

namespace App\Http\Resources\Exams;

use App\Models\ExamAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;

/**
 * Назначенный экзамен глазами сдающего: сам экзамен, срок и как идут дела.
 *
 * Сводка (`summary`) кладётся контроллером заранее — считает её ExamResults,
 * тот же, что строит таблицу автору: «сдал» в списке и «сдал» в результатах
 * обязаны быть одним словом.
 *
 * @mixin ExamAssignment
 */
final class AssignedExamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'exam' => ExamResource::make($this->whenLoaded('exam')),
            'due_at' => $this->due_at,
            'assigned_at' => $this->created_at,

            // Идущая попытка, если есть: по ней экран продолжает таймер после
            // перезагрузки страницы.
            'window' => $this->resource->hasOpenWindowAt(Date::now()) ? [
                'started_at' => $this->started_at,
                'expires_at' => $this->expires_at,
            ] : null,

            'summary' => $this->whenNotNull($this->summary ?? null),
        ];
    }
}
