<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lesson
 */
final class LessonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'video_url' => $this->video_url,
            'video_upload_url' => $this->videoUrl(),
            'video_name' => $this->video_name,
            'video_size' => $this->video_size,
            'duration_minutes' => $this->duration_minutes,
            'position' => $this->position,
            'has_quiz' => $this->whenLoaded('quiz', fn (): bool => $this->quiz !== null),
            // Only the lesson endpoint loads the body; outlines stay light.
            'content' => $this->when($request->routeIs('lms.lessons.show'), fn (): ?string => $this->content),
            'content_json' => $this->when($request->routeIs('lms.lessons.show'), fn (): ?array => $this->content_json),
            'attachments' => LessonAttachmentResource::collection($this->whenLoaded('attachments')),
            'answers' => LessonAnswerResource::collection($this->whenLoaded('answers')),
            'quiz' => QuizResource::make($this->whenLoaded('quiz')),
            // Attached by the controller from the learner's completions.
            'is_completed' => $this->is_completed_by_learner,

            // Урок, из-за которого этот пока нельзя закрыть: курс проходят по
            // порядку. Null — путь открыт.
            'blocked_by' => $this->blocked_by,
            'neighbours' => $this->neighbours,
            'course_title' => $this->course_title,
            'course_slug' => $this->course_slug,
            'own_attempts' => $this->own_attempts,
        ];
    }
}
