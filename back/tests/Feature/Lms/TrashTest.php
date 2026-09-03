<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LessonAttachment;
use App\Models\Regulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Корзина: удалённое, но ещё не стёртое.
 *
 * Удаление курса и документа всегда было мягким — за одним стоит чужой
 * прогресс, за другим отметки об ознакомлении. Но пока корзины не было, мягкое
 * удаление ничем не отличалось от настоящего: вернуть удалённое можно было
 * только запросом в базу.
 */
final class TrashTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
    }

    public function test_a_deleted_course_lands_in_the_trash_with_the_name_of_who_threw_it(): void
    {
        $course = Course::factory()->withLessons(2)->create(['title' => 'Кассовая дисциплина']);
        $author = $this->author();

        $this->actingAs($author)
            ->deleteJson(route('lms.courses.destroy', $course))
            ->assertNoContent();

        // Из каталога пропал.
        $this->actingAs($author)
            ->getJson(route('lms.courses.index'))
            ->assertOk()
            ->assertJsonMissing(['title' => 'Кассовая дисциплина']);

        $this->actingAs($author)
            ->getJson(route('lms.trash.index'))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Кассовая дисциплина')
            ->assertJsonPath('data.0.kind', 'course')
            ->assertJsonPath('data.0.deleted_by', $author->name)
            // Сколько уроков уйдёт, если стереть насовсем.
            ->assertJsonPath('data.0.lessons', 2);
    }

    public function test_a_restored_course_comes_back_to_the_catalogue(): void
    {
        $course = Course::factory()->create(['title' => 'Приёмка товара']);
        $author = $this->author();

        $this->actingAs($author)->deleteJson(route('lms.courses.destroy', $course))->assertNoContent();

        $this->actingAs($author)
            ->postJson(route('lms.trash.courses.restore', $course->getKey()))
            ->assertOk()
            ->assertJsonPath('data.title', 'Приёмка товара');

        $this->actingAs($author)
            ->getJson(route('lms.courses.index'))
            ->assertOk()
            ->assertJsonFragment(['title' => 'Приёмка товара']);

        // Вернувшийся курс — не удалённый: в корзине его больше нет, и следа
        // о том, кто выбрасывал, тоже.
        $this->actingAs($author)
            ->getJson(route('lms.trash.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertNull($course->fresh()->deleted_by);
    }

    /** Прогресс сотрудников переживает удаление — ради этого оно и мягкое. */
    public function test_a_restored_course_keeps_the_progress_of_its_learners(): void
    {
        $course = Course::factory()->withLessons(1)->create();
        $learner = $this->learner();

        $this->actingAs($learner)
            ->postJson(route('lms.enroll', $course))
            ->assertCreated();

        $this->actingAs($this->author())->deleteJson(route('lms.courses.destroy', $course))->assertNoContent();
        $this->actingAs($this->author())->postJson(route('lms.trash.courses.restore', $course->getKey()))->assertOk();

        $this->assertSame(1, Enrollment::query()->where('course_id', $course->getKey())->count());
    }

    public function test_a_deleted_document_lands_in_the_same_trash(): void
    {
        $document = Regulation::factory()->published()->create(['title' => 'Правила отпуска']);
        $author = $this->author();

        $this->actingAs($author)
            ->deleteJson(route('lms.regulations.destroy', $document))
            ->assertNoContent();

        $this->actingAs($author)
            ->getJson(route('lms.trash.index'))
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'document')
            ->assertJsonPath('data.0.title', 'Правила отпуска');

        $this->actingAs($author)
            ->postJson(route('lms.trash.documents.restore', $document->getKey()))
            ->assertOk();

        $this->assertNull($document->fresh()->deleted_at);
    }

    /** Кто не вправе удалять, тот и корзины не видит. */
    public function test_a_learner_cannot_open_the_trash(): void
    {
        $this->actingAs($this->learner())
            ->getJson(route('lms.trash.index'))
            ->assertForbidden();
    }

    /**
     * Стереть насовсем может только администратор: это единственное действие
     * во всей базе знаний, после которого возвращать нечего.
     */
    public function test_only_an_administrator_wipes_a_course_for_good(): void
    {
        $course = Course::factory()->create();
        $author = $this->author();

        $this->actingAs($author)->deleteJson(route('lms.courses.destroy', $course))->assertNoContent();

        $this->actingAs($author)
            ->deleteJson(route('lms.trash.courses.purge', $course->getKey()))
            ->assertForbidden();

        $this->actingAs($this->administrator())
            ->deleteJson(route('lms.trash.courses.purge', $course->getKey()))
            ->assertNoContent();

        $this->assertSame(0, Course::withTrashed()->count());
    }

    /**
     * Вместе со стёртым курсом уходят и его файлы: каскад в базе про внешние
     * ключи, а не про чужое хранилище, и подметать за собой некому.
     */
    public function test_wiping_a_course_takes_its_files_out_of_storage(): void
    {
        $course = Course::factory()->withLessons(1)->create();
        $lesson = $course->lessons()->firstOrFail();
        $author = $this->author();

        $this->actingAs($author)
            ->postJson(route('lms.attachments.store', $lesson), [
                'file' => UploadedFile::fake()->create('инструкция.pdf', 12, 'application/pdf'),
            ])
            ->assertCreated();

        $path = LessonAttachment::query()->sole()->path;
        Storage::disk('s3')->assertExists($path);

        $this->actingAs($author)->deleteJson(route('lms.courses.destroy', $course))->assertNoContent();

        $this->actingAs($this->administrator())
            ->deleteJson(route('lms.trash.courses.purge', $course->getKey()))
            ->assertNoContent();

        Storage::disk('s3')->assertMissing($path);
        $this->assertSame(0, LessonAttachment::query()->count());
    }

    /** Живой курс в корзине не лежит и стереть его этим путём нельзя. */
    public function test_a_living_course_is_not_in_the_trash(): void
    {
        $course = Course::factory()->create();

        $this->actingAs($this->administrator())
            ->deleteJson(route('lms.trash.courses.purge', $course->getKey()))
            ->assertNotFound();

        $this->actingAs($this->author())
            ->getJson(route('lms.trash.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
