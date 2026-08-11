<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CourseStatus;
use App\Enums\CourseVisibility;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
final class CourseFactory extends Factory
{
    protected $model = Course::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::ucfirst(fake()->unique()->words(4, true));

        return [
            'author_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'summary' => fake()->sentence(),
            'description' => fake()->paragraphs(2, true),
            'status' => CourseStatus::Draft,
            'visibility' => CourseVisibility::Public,
            'published_at' => null,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => CourseStatus::Published,
            'published_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ]);
    }

    /**
     * Курс, закрытый от всех, кроме автора и допущенных.
     *
     * Называется не private: так метод назвать нельзя, слово занято языком.
     */
    public function closed(): self
    {
        return $this->state(fn (): array => ['visibility' => CourseVisibility::Private]);
    }

    /**
     * A published course with one module holding the given number of lessons.
     */
    public function withLessons(int $count = 3): self
    {
        return $this->published()->afterCreating(function (Course $course) use ($count): void {
            $module = CourseModuleFactory::new()->create(['course_id' => $course->id, 'position' => 0]);

            LessonFactory::new()->count($count)->sequence(
                fn ($sequence): array => ['position' => $sequence->index],
            )->create(['module_id' => $module->id]);
        });
    }
}
