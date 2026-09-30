<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasApprovals;
use App\Models\Concerns\HasVersions;
use App\Models\Contracts\Approvable;
use App\Models\Contracts\PartOfCourse;
use App\Observers\LessonObserver;
use App\Support\Lms\BlockIdentifier;
use App\Support\Lms\StoredFiles;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\Storage;

#[ObservedBy(LessonObserver::class)]
#[Fillable(['module_id', 'title', 'slug', 'content', 'content_json', 'video_url', 'video_path', 'video_disk', 'video_name', 'video_size', 'duration_minutes', 'position', 'published_at'])]
class Lesson extends Model implements Approvable, PartOfCourse
{
    /** @use HasFactory<LessonFactory> */
    use HasApprovals, HasFactory, HasVersions;

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
        return ['content_json' => 'array', 'published_at' => 'datetime'];
    }

    /**
     * Виден ли урок тем, кто курс проходит (2026-09-30).
     *
     * У урока своё состояние, как у курса и документа, и появилось оно ради
     * согласования: пока урок не согласован — или пока автор не выложил его
     * сам, — людям его не показывают. Незаконченный урок и раньше не стоило
     * показывать, просто скрыть его было нечем.
     */
    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * Только выложенные уроки.
     *
     * Этим же условием отбирают уроки связи `lessons()` у курса и модуля:
     * прогресс, план, статистика и корпус консультанта считают ровно то, что
     * людям видно. Черновики едут отдельной связью — `allLessons()`.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('lessons.published_at');
    }

    /* ---------- Согласование (2026-09-30) ---------- */

    /**
     * Выложить урок по согласию всех.
     *
     * Дата ставится один раз: урок, снятый с публикации и выложенный снова, не
     * становится новым — люди уже видели его под этим адресом.
     */
    public function publishAfterApproval(): void
    {
        $this->published_at ??= now();

        $this->save();
    }

    public function approvalTitle(): string
    {
        return (string) $this->title;
    }

    /**
     * Адрес урока внутри курса: без курса его не собрать, и потому курс тут
     * подгружается, если его ещё не читали.
     */
    public function approvalPath(): string
    {
        return '/lms/'.($this->owningCourse()?->slug ?? '').'/lessons/'.$this->getKey();
    }

    public function approvalLabel(): string
    {
        return 'Урок';
    }

    /**
     * @return BelongsTo<CourseModule, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    /**
     * Файлы общей версии урока.
     *
     * Файлы версий сюда не попадают намеренно (2026-09-25), как и у документа:
     * у каждой версии свой бланк, и общий список означал бы, что розница видит
     * приложение офиса просто потому, что оно лежит при том же уроке. Всё
     * вместе — только для уборки, см. allAttachments().
     *
     * @return HasMany<LessonAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(LessonAttachment::class)->whereNull('version_id');
    }

    /**
     * Все файлы урока, включая версии, — для уборки за удалённым: строки уйдут
     * каскадом, а файлы остались бы лежать в хранилище навсегда.
     *
     * @return HasMany<LessonAttachment, $this>
     */
    public function allAttachments(): HasMany
    {
        return $this->hasMany(LessonAttachment::class);
    }

    /**
     * Документы и справочники, приложенные к уроку.
     *
     * Не файлы: те лежат в самом уроке. Это ссылки на правила, к которым урок
     * отправляет читателя дочитать, — односторонние, в заданном порядке и без
     * границы разделов, как «частые вопросы» у документа.
     *
     * @return BelongsToMany<Regulation, $this>
     */
    public function materials(): BelongsToMany
    {
        return $this->belongsToMany(Regulation::class, 'lesson_materials')
            ->withPivot('position')
            ->withTimestamps()
            ->orderBy('lesson_materials.position')
            ->orderBy('lesson_materials.id');
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
     * Опрос при уроке: что человек думает, а не что понял.
     *
     * Рядом с тестом, а не вместо: у теста есть ключ и планка, у опроса нет ни
     * того ни другого — см. Survey.
     *
     * @return MorphOne<Survey, $this>
     */
    public function survey(): MorphOne
    {
        return $this->morphOne(Survey::class, 'surveyable');
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
