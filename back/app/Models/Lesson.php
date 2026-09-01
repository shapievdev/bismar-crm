<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Contracts\PartOfCourse;
use App\Observers\LessonObserver;
use App\Support\Lms\BlockIdentifier;
use App\Support\Lms\StoredFiles;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\Storage;

#[ObservedBy(LessonObserver::class)]
#[Fillable(['module_id', 'title', 'slug', 'content', 'content_json', 'video_url', 'video_path', 'video_disk', 'video_name', 'video_size', 'duration_minutes', 'position'])]
class Lesson extends Model implements PartOfCourse
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    public function owningCourse(): ?Course
    {
        return $this->loadMissing('module.course')->module?->course;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // Without this the jsonb column comes back as a raw string and every
        // consumer would have to decode it by hand.
        return ['content_json' => 'array'];
    }

    /**
     * @return BelongsTo<CourseModule, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    /**
     * @return HasMany<LessonAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(LessonAttachment::class);
    }

    /**
     * Текстовые изложения того, что урок содержит: записи, файлов, блоков
     * статьи. Читателю не видны — по ним ищет консультант.
     *
     * @return HasMany<LessonTranscript, $this>
     */
    public function transcripts(): HasMany
    {
        return $this->hasMany(LessonTranscript::class);
    }

    /**
     * Куски расшифровок этого урока — то, что находит поиск.
     *
     * @return HasMany<TranscriptSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptSegment::class);
    }

    /**
     * Вопросы, которые урок разбирает, с ответами и местом каждого.
     *
     * В отличие от passages, это не производное от текста, а то, что автор
     * написал сам, — и главное, по чему ищет консультант.
     *
     * @return HasMany<LessonAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(LessonAnswer::class)->orderBy('position');
    }

    /**
     * @return MorphOne<Quiz, $this>
     */
    public function quiz(): MorphOne
    {
        return $this->morphOne(Quiz::class, 'quizzable');
    }

    /**
     * Строки таблицы с уже проставленной обратной ссылкой на урок.
     *
     * Обратная ссылка не украшение: каждая строка проверяет, существует ли ещё
     * место, на которое она указывает, а для текста это значит заглянуть в
     * статью. Без неё строка лезла бы за уроком отдельным запросом — за тем
     * самым уроком, который её и загрузил.
     */
    public function loadAnswers(): static
    {
        $this->load('answers.attachment');
        $this->answers->each->setRelation('lesson', $this);

        return $this;
    }

    /** Есть ли у урока запись — загруженная или по ссылке. */
    public function hasVideo(): bool
    {
        return $this->video_path !== null || $this->video_url !== null;
    }

    /**
     * Идентификаторы блоков статьи — то, на что может сослаться строка таблицы.
     *
     * @return list<string>
     */
    public function blockIds(): array
    {
        return app(BlockIdentifier::class)->identifiers($this->content_json);
    }

    /**
     * A short-lived signed URL for an uploaded video, or null when the lesson
     * has none. Links to YouTube or Vimeo live in video_url instead.
     */
    public function videoUrl(): ?string
    {
        if ($this->video_path === null || $this->video_disk === null) {
            return null;
        }

        return Storage::disk($this->video_disk)->temporaryUrl(
            $this->video_path,
            now()->addMinutes(config('lms.attachment_url_ttl_minutes')),
        );
    }

    public function deleteVideoFromStorage(): void
    {
        if ($this->video_disk !== null) {
            StoredFiles::discard($this->video_disk, $this->video_path);
        }
    }

    /**
     * The course this lesson belongs to, resolved through its module.
     */
    public function course(): ?Course
    {
        return $this->module?->course;
    }
}
