<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\User;
use App\Support\SlugGenerator;
use Illuminate\Support\Facades\DB;

final readonly class SaveCourse
{
    public function __construct(private SlugGenerator $slugGenerator) {}

    /**
     * @param  array{title: string, summary?: ?string, description?: ?string, status: string, category_id?: ?int}  $attributes
     */
    public function create(array $attributes, User $author): Course
    {
        return DB::transaction(function () use ($attributes, $author): Course {
            $status = CourseStatus::from($attributes['status']);

            return Course::create([
                'author_id' => $author->getKey(),
                'category_id' => $attributes['category_id'] ?? null,
                'title' => $attributes['title'],
                'slug' => $this->slugGenerator->generate($attributes['title'], Course::class),
                'summary' => $attributes['summary'] ?? null,
                'description' => $attributes['description'] ?? null,
                'status' => $status,
                'published_at' => $status === CourseStatus::Published ? now() : null,
            ]);
        });
    }

    /**
     * @param  array{title: string, summary?: ?string, description?: ?string, status: string, category_id?: ?int}  $attributes
     */
    public function update(Course $course, array $attributes): Course
    {
        return DB::transaction(function () use ($course, $attributes): Course {
            $status = CourseStatus::from($attributes['status']);

            // The slug is the course's public address, so it only follows the
            // title while the course has never been published.
            if ($course->published_at === null && $course->title !== $attributes['title']) {
                $course->slug = $this->slugGenerator->generate(
                    $attributes['title'],
                    Course::class,
                    $course->getKey(),
                );
            }

            $course->fill([
                'title' => $attributes['title'],
                'category_id' => $attributes['category_id'] ?? null,
                'summary' => $attributes['summary'] ?? null,
                'description' => $attributes['description'] ?? null,
                'status' => $status,
                // First publication is stamped once and then preserved.
                'published_at' => $status === CourseStatus::Published
                    ? ($course->published_at ?? now())
                    : $course->published_at,
            ]);

            $course->save();

            return $course;
        });
    }
}
