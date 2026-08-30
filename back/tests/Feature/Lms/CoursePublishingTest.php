<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\CourseStatus;
use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Кто выпускает материал к людям.
 *
 * Право публиковать — отдельное от права править: автор собирает материал, а
 * решение показать его компании принимает тот, кому это доверено. Право нужно на
 * сам перевод в «опубликован», а не на пребывание в нём — иначе редактор без
 * него не смог бы сохранить у опубликованного материала ни одной правки.
 */
final class CoursePublishingTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_publishing_takes_the_right_to_publish(): void
    {
        $course = Course::factory()->create(['status' => CourseStatus::Draft]);

        $this->actingAs($this->editor())
            ->putJson(route('lms.courses.update', $course), [
                'title' => $course->title,
                'status' => CourseStatus::Published->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(CourseStatus::Draft, $course->fresh()?->status);
    }

    public function test_someone_with_the_right_publishes(): void
    {
        $course = Course::factory()->create(['status' => CourseStatus::Draft]);

        $this->actingAs($this->author())
            ->putJson(route('lms.courses.update', $course), [
                'title' => $course->title,
                'status' => CourseStatus::Published->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', CourseStatus::Published->value);
    }

    /**
     * Правка опубликованного материала — не публикация.
     *
     * Статус уходит в каждом запросе формы, поэтому проверка на неизменённом
     * значении отклоняла любое сохранение: редактор без права публиковать не мог
     * исправить у опубликованного материала даже опечатку в названии.
     */
    public function test_a_published_course_stays_editable_without_the_right(): void
    {
        $course = Course::factory()->published()->create();

        $this->actingAs($this->editor())
            ->putJson(route('lms.courses.update', $course), [
                'title' => 'Исправленное название',
                'status' => CourseStatus::Published->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Исправленное название')
            ->assertJsonPath('data.status', CourseStatus::Published->value);
    }

    /**
     * Снять с публикации редактор вправе — это не выпуск материала к людям, — а
     * вернуть обратно уже нет: с этого шага он публикует.
     */
    public function test_what_was_unpublished_is_not_republished_without_the_right(): void
    {
        $course = Course::factory()->published()->create();
        $editor = $this->editor();

        $this->actingAs($editor)
            ->putJson(route('lms.courses.update', $course), [
                'title' => $course->title,
                'status' => CourseStatus::Draft->value,
            ])
            ->assertOk();

        $this->actingAs($editor)
            ->putJson(route('lms.courses.update', $course), [
                'title' => $course->title,
                'status' => CourseStatus::Published->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(CourseStatus::Draft, $course->fresh()?->status);
    }

    public function test_a_new_course_cannot_be_created_published_without_the_right(): void
    {
        $this->actingAs($this->editor())
            ->postJson(route('lms.courses.store'), [
                'title' => 'Сразу к людям',
                'status' => CourseStatus::Published->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseCount('courses', 0);
    }
}
