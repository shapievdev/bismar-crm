<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

#[Fillable(['module_id', 'title', 'slug', 'content', 'content_json', 'video_url', 'video_path', 'video_disk', 'video_name', 'video_size', 'duration_minutes', 'position'])]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

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
     * @return HasOne<Quiz, $this>
     */
    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
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
        if ($this->video_path !== null && $this->video_disk !== null) {
            Storage::disk($this->video_disk)->delete($this->video_path);
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
