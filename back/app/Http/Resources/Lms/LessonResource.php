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

            // Документы и справочники, приложенные к уроку, — со статьёй
            // внутри: их читают, не покидая урока. Отбирает контроллер —
            // показывать материал можно только тому, кому он и сам по себе
            // открыт.
            'materials' => LessonMaterialResource::collection($this->whenLoaded('materials')),
            'answers' => LessonAnswerResource::collection($this->whenLoaded('answers')),
            'quiz' => QuizResource::make($this->whenLoaded('quiz')),

            // Опрос при уроке — рядом с тестом и о другом: что человек думает.
            // Обязательный держит зачёт урока, см. MaterialDues.
            'survey' => SurveyResource::make($this->whenLoaded('survey')),
            // Attached by the controller from the learner's completions.
            'is_completed' => $this->is_completed_by_learner,

            // Урок, из-за которого этот пока нельзя закрыть: курс проходят по
            // порядку. Null — путь открыт.
            'blocked_by' => $this->blocked_by,
            'neighbours' => $this->neighbours,
            'course_title' => $this->course_title,
            'course_slug' => $this->course_slug,
            'own_attempts' => $this->own_attempts,

            /*
             * Версии урока — тот же урок, рассказанный своим людям
             * (2026-09-25).
             *
             * Только названиями, без тел: пять версий весили бы пятью
             * статьями, а смотрят за раз одну. Общей версии в списке нет — она
             * сам урок, и экран ставит её первой строкой сам.
             */
            'versions' => $this->when(
                $this->available_versions !== null,
                fn (): array => MaterialVersionResource::collection($this->available_versions)->resolve(),
            ),

            /*
             * Тело, которое открывается первым: версия этого человека, а если
             * ни одна не совпала — ничего, и смотрится общий урок.
             *
             * Статья, запись, файлы и проверка урока выше остаются общей
             * версией всегда и при любом выборе: по ним работает редактор, и
             * подмена содержимого под ним однажды сохранила бы текст версии в
             * сам урок.
             */
            'version' => $this->when(
                $this->shown_version !== null,
                fn () => MaterialVersionResource::make($this->shown_version),
            ),
        ];
    }
}
