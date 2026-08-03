<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseStatus;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable(['author_id', 'title', 'slug', 'summary', 'description', 'cover_path', 'status', 'published_at'])]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory, SoftDeletes;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CourseStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * A short-lived signed URL for the cover, so the bucket stays private.
     * Generated locally — no call to S3 — so it is cheap enough for listings.
     */
    public function coverUrl(): ?string
    {
        if ($this->cover_path === null) {
            return null;
        }

        return Storage::disk('s3')->temporaryUrl(
            $this->cover_path,
            now()->addMinutes(config('lms.attachment_url_ttl_minutes')),
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return HasMany<CourseModule, $this>
     */
    public function modules(): HasMany
    {
        // Ties on position are broken by id so the order can never flap
        // between requests while a reorder is half-applied.
        return $this->hasMany(CourseModule::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Every lesson in the course, in reading order. Progress is measured
     * against this set, so it must not depend on which module a lesson sits in.
     *
     * @return HasManyThrough<Lesson, CourseModule, $this>
     */
    public function lessons(): HasManyThrough
    {
        return $this->hasManyThrough(Lesson::class, CourseModule::class, 'course_id', 'module_id')
            ->orderBy('course_modules.position')
            ->orderBy('course_modules.id')
            ->orderBy('lessons.position')
            ->orderBy('lessons.id');
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOpenToLearners(Builder $query): void
    {
        $query->where('status', CourseStatus::Published);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeMatching(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        // The databases use the C collation, whose lower() folds ASCII only, so
        // a plain ILIKE never matches Russian text. Collating to ICU fixes it.
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        $query->where(function (Builder $query) use ($pattern): void {
            foreach (['title', 'summary', 'description'] as $column) {
                $query->orWhereRaw(sprintf('%s COLLATE "und-x-icu" ILIKE ?', $column), [$pattern]);
            }
        });
    }
}
