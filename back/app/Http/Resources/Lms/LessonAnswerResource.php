<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\LessonAnswer;
use App\Support\Ai\Embedder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LessonAnswer
 */
final class LessonAnswerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'question' => $this->question,
            'answer' => $this->answer,

            'source_kind' => $this->source_kind->value,
            'source_attachment_id' => $this->source_attachment_id,
            'source_seconds' => $this->source_seconds,
            'source_page' => $this->source_page,
            'source_block_id' => $this->source_block_id,

            // Указывает ли строка ещё на существующее место. Файл могли
            // удалить, абзац — вырезать; ответ от этого верным быть не
            // перестал, но проверить его негде, и автору стоит это показать.
            'source_is_live' => $this->hasLiveSource(),

            // Есть ли у строки векторы. Пока их нет, смысловой поиск её не
            // находит — окно между сохранением и очередью видно в интерфейсе,
            // а не только в логах.
            //
            // null, когда смыслового поиска нет вовсе: без модели эмбеддингов
            // векторы не появятся никогда, и «ещё не посчитаны» превращается из
            // сообщения о задержке в вечное обещание.
            'is_indexed' => $this->when(
                app(Embedder::class)->isAvailable(),
                fn (): bool => $this->question_embedding !== null && $this->answer_embedding !== null,
            ),
        ];
    }
}
