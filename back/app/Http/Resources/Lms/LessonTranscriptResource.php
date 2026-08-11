<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\LessonTranscript;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LessonTranscript
 */
final class LessonTranscriptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_kind' => $this->source_kind->value,
            'source_attachment_id' => $this->source_attachment_id,
            'source_block_id' => $this->source_block_id,

            // Выведена из текста блока, а не загружена: такую нельзя удалить —
            // она вернётся при следующем сохранении урока, — и показывать её
            // надо иначе.
            'is_derived' => $this->is_derived,

            'original_name' => $this->original_name,
            'format' => $this->format,

            // Текст целиком, а не обрезок: автор его правит, и показать ему
            // первую строку с многоточием — значит не показать ничего. Отдаётся
            // только тому, кто правит курс: весь маршрут под courses.update.
            'content' => $this->content,
            'characters' => mb_strlen((string) $this->content),

            'segments_count' => $this->whenLoaded('segments', fn (): int => $this->segments->count()),

            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
