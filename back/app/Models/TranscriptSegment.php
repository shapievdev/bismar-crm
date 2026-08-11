<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Кусок расшифровки — то, что находит поиск и что уходит в промпт целиком.
 *
 * Занял место прежних lesson_passages и устроен так же: производное от текста,
 * пересобирается вместе с ним и отдельного смысла без него не имеет. Разница в
 * том, что текст этот бывает не только набранным — и что кусок помнит, на какой
 * секунде записи или на какой странице документа он сказан.
 */
#[Fillable([
    'transcript_id',
    'lesson_id',
    'position',
    'heading',
    'content',
    'starts_at_seconds',
    'page',
    'source_block_id',
])]
class TranscriptSegment extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<LessonTranscript, $this>
     */
    public function transcript(): BelongsTo
    {
        return $this->belongsTo(LessonTranscript::class, 'transcript_id');
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
