<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnswerSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка таблицы урока: вопрос, ответ на него и место, где он написан.
 *
 * Не путать с App\Actions\Ai\Answer — то ответ консультанта читателю, собранный
 * на лету. Здесь ответ, написанный автором заранее и живущий в базе.
 *
 * В отличие от LessonPassage, это не производное от текста урока, а
 * самостоятельный материал: пересборка текста её не трогает, и потерять её
 * можно только удалив.
 */
#[Fillable([
    'lesson_id',
    'position',
    'question',
    'answer',
    'source_kind',
    'source_attachment_id',
    'source_seconds',
    'source_page',
    'source_block_id',
])]
class LessonAnswer extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['source_kind' => AnswerSource::class];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @return BelongsTo<LessonAttachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(LessonAttachment::class, 'source_attachment_id');
    }

    /**
     * Указывает ли строка ещё на существующее место.
     *
     * Файл могли удалить, а блок текста — вырезать при правке урока. Ответ от
     * этого верным быть не перестал, но проверить его читателю больше негде,
     * и автору стоит об этом сказать.
     */
    public function hasLiveSource(): bool
    {
        return match ($this->source_kind) {
            AnswerSource::Attachment => $this->source_attachment_id !== null,
            AnswerSource::Video => $this->lesson?->hasVideo() ?? false,
            AnswerSource::Text => $this->source_block_id === null
                || in_array($this->source_block_id, $this->lesson?->blockIds() ?? [], strict: true),
        };
    }
}
