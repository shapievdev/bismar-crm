<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnswerSource;
use App\Models\Contracts\PartOfCourse;
use App\Support\Ai\SourceLocation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Текстовое изложение одной единицы содержания урока.
 *
 * Записи, приложенного файла или блока статьи. Читатель её не видит: это не
 * часть материала, а то, чем материал становится доступен машине — иначе
 * часовая запись для базы знаний просто не существует.
 */
#[Fillable([
    'lesson_id',
    'regulation_id',
    'source_kind',
    'source_attachment_id',
    'source_block_id',
    'content',
    'is_derived',
    'original_name',
    'format',
])]
class LessonTranscript extends Model implements PartOfCourse
{
    public function owningCourse(): ?Course
    {
        return $this->loadMissing('lesson.module.course')->lesson?->module?->course;
    }

    /**
     * Документ, которому принадлежит расшифровка. Null у урочной — хозяин у
     * неё ровно один, и это проверяет сама база.
     *
     * @return BelongsTo<Regulation, $this>
     */
    public function regulation(): BelongsTo
    {
        return $this->belongsTo(Regulation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_kind' => AnswerSource::class,
            'is_derived' => 'boolean',
        ];
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
     * @return HasMany<TranscriptSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptSegment::class, 'transcript_id')->orderBy('position');
    }

    /**
     * Куда ведёт найденное в этой расшифровке.
     *
     * Секунда берётся у сегмента, а не отсюда: расшифровка целиком не стоит ни
     * на какой секунде, стоит на ней конкретная реплика.
     */
    public function locationAt(?int $seconds, ?int $page): SourceLocation
    {
        return new SourceLocation(
            kind: $this->source_kind,
            seconds: $seconds,
            page: $page,
            blockId: $this->source_block_id,
            attachmentName: $this->attachment?->name,
        );
    }
}
