<?php

declare(strict_types=1);

namespace App\Http\Resources\Ai;

use App\Models\ConsultantQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin ConsultantQuestion
 */
final class QuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question,

            // Чем искали, если вопрос был продолжением разговора. Иначе строка
            // «а сколько это сохнет?» стоит рядом с источниками про краску без
            // всякого объяснения, откуда они взялись.
            'searched_as' => $this->searched_as,

            // Ответ в списке нужен для беглого взгляда, а не для чтения
            // целиком: длинный текст растянул бы строку на пол-экрана.
            'answer' => Str::limit((string) $this->answer, 300),

            'outcome' => $this->outcome->value,
            'outcome_label' => $this->outcome->label(),
            'hint' => $this->outcome->hint(),

            // Чем ответили. По доле «текста урока» видно, насколько таблицы
            // уроков заполнены: пока она высока, авторам есть что размечать.
            'answered_from' => $this->answered_from?->value,
            'answered_from_label' => $this->answered_from?->label(),

            // Что сказал о полученном ответе сам спрашивавший — и просил ли
            // дописать его. Заявка тем и отличается от догадки журнала о
            // пробеле, что за ней стоит живой человек.
            'feedback' => $this->feedback?->value,
            'feedback_label' => $this->feedback?->label(),
            'requested_at' => $this->requested_at?->toIso8601String(),
            'request_note' => $this->request_note,

            // Дописанный ответ и урок, в который он занесён.
            'resolution' => $this->resolution,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_lesson' => $this->whenLoaded(
                'resolutionLesson',
                fn (): ?array => $this->resolutionLesson === null ? null : [
                    'id' => $this->resolutionLesson->id,
                    'title' => $this->resolutionLesson->title,
                ],
            ),
            'resolved_by' => $this->whenLoaded('resolvedBy', fn (): ?string => $this->resolvedBy?->name),

            'retrieved' => $this->retrieved,
            'cited' => $this->cited,
            'model' => $this->model,
            'duration_ms' => $this->duration_ms,

            'asked_by' => $this->whenLoaded('asker', fn (): ?string => $this->asker?->name),
            'asked_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
