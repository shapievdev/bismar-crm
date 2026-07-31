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
            'lessons' => LessonResource::collection($this->whenLoaded('lessons')),
        ];
    }
}
