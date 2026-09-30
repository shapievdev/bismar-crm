<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lesson>
 */
final class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::ucfirst(fake()->unique()->words(3, true));

        return [
            'module_id' => CourseModule::factory(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'content' => fake()->paragraphs(2, true),
            'video_url' => null,
            'duration_minutes' => fake()->numberBetween(5, 60),
            'position' => 0,

            /*
             * Выложенный — умолчание фабрики, а не приложения (2026-09-30).
             *
             * В приложении новый урок заводится черновиком: пока он не
             * согласован или не выложен автором, людям его не показывают. А в
             * тестах урок почти всегда нужен тот, который проходят, — и
             * требовать `->published()` в сотне мест значило бы писать про
             * состояние там, где речь совсем о другом. Черновик просят прямо:
             * `LessonFactory::new()->draft()`.
             */
            'published_at' => now(),
        ];
    }

    /** Урок, которого людям ещё не видно: его пишут или согласуют. */
    public function draft(): self
    {
        return $this->state(fn (): array => ['published_at' => null]);
    }
}
