<?php

declare(strict_types=1);

namespace App\Http\Resources\Ai;

use App\Enums\ConsultantOutcome;
use App\Models\ConsultantQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Один разговор сотрудника с консультантом, как он видит его сам.
 *
 * Форма совпадает с той, что возвращает сам вопрос: страница чата не должна
 * знать, приехал ответ только что или лежал в истории.
 *
 * @mixin ConsultantQuestion
 */
final class ExchangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question,
            'answer' => [
                'answer' => $this->answer,
                'verbatim' => $this->outcome === ConsultantOutcome::Verbatim,
                'sources' => $this->sources ?? [],
                'related' => $this->related ?? [],
                'experts' => $this->experts ?? [],
            ],

            // Что сотрудник сказал об ответе и просил ли дописать его.
            'feedback' => $this->feedback?->value,
            'requested' => $this->requested_at !== null,

            // Дополнение автора, если оно уже есть.
            'resolution' => $this->when($this->isResolved(), fn (): array => $this->resolution()),

            'asked_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Ответ, дописанный автором после заявки.
     *
     * С уроком, куда он занесён: сотруднику мало услышать ответ — по ссылке он
     * увидит его на месте, вместе со всем, что рядом сказано по теме.
     *
     * @return array<string, mixed>
     */
    private function resolution(): array
    {
        $lesson = $this->resolutionLesson;
        $course = $lesson?->module?->course;

        return [
            'answer' => $this->resolution,
            'answered_at' => $this->resolved_at?->toIso8601String(),

            // Новым дополнение остаётся до первой же загрузки переписки: его
            // показывают как новость, а не как строку, которую сотрудник уже
            // читал.
            'is_new' => $this->resolution_seen_at === null,

            'lesson' => $lesson === null ? null : [
                'lesson_id' => $lesson->id,
                'lesson_title' => $lesson->title,
                'course_title' => $course?->title,
                'course_slug' => $course?->slug,
            ],
        ];
    }
}
