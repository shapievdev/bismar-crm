<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\CourseModule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CourseModule
 */
final class CourseModuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'position' => $this->position,
            /*
             * Уроки модуля — выложенные или все.
             *
             * Связь `lessons` отдаёт только выложенные, `allLessons` — вместе с
             * черновиками; какую из них загрузить, решает контроллер: читателю
             * черновик видеть нечего, а тот, кто курс ведёт, иначе не нашёл бы
             * собственный неоконченный урок (2026-09-30).
             */
            'lessons' => LessonResource::collection(
                $this->relationLoaded('allLessons')
                    ? $this->allLessons
                    : $this->whenLoaded('lessons'),
            ),
        ];
    }
}
