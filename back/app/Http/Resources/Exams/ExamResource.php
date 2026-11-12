<?php

declare(strict_types=1);

namespace App\Http\Resources\Exams;

use App\Http\Resources\Lms\QuizResource;
use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;

/**
 * Экзамен в ответе API — и сдающему, и ведущему: разницу делает не ресурс, а
 * то, что в него загрузили. Вопросы едут только загруженными (`whenLoaded`), а
 * ключ в них прячет сам QuizResource — по праву на ведение экзаменов.
 *
 * @mixin Exam
 */
final class ExamResource extends JsonResource
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
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'opens_at' => $this->opens_at,
            'closes_at' => $this->closes_at,
            'time_limit_minutes' => $this->time_limit_minutes,
            'shuffle_questions' => $this->shuffle_questions,

            // Можно ли сесть прямо сейчас — и почему нельзя, словами: экран не
            // должен выводить это сам из статуса и двух дат, разойдясь с
            // отказом сервера на первой же правке правила.
            'is_open' => $this->resource->isOpenAt(Date::now()),
            'closed_reason' => $this->resource->closedReasonAt(Date::now()),

            'quiz' => $this->whenLoaded('quiz', fn () => QuizResource::make($this->quiz)),
            'author' => $this->whenLoaded('author', fn () => $this->author === null ? null : [
                'id' => $this->author->getKey(),
                'name' => $this->author->name,
            ]),

            'assignments_count' => $this->whenCounted('assignments'),
        ];
    }
}
