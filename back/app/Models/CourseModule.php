<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Contracts\PartOfCourse;
use Database\Factories\CourseModuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['course_id', 'title', 'description', 'position'])]
class CourseModule extends Model implements PartOfCourse
{
    /** @use HasFactory<CourseModuleFactory> */
    use HasFactory;

    public function owningCourse(): ?Course
    {
        return $this->loadMissing('course')->course;
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'module_id')->orderBy('position')->orderBy('id');
    }
}
