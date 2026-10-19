<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\CourseVisibility;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\Favourite;
use App\Models\Lesson;
use App\Models\Regulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Избранное: материалы, которые сотрудник отложил себе сам.
 *
 * Не план обучения и не запись на курс: план назначают, запись заводится сама
 * от первого открытого урока. Избранное человек ведёт для себя, и видит его
 * только он.
 *
 * Главное, что здесь проверяется, — закладка не открывает того, что было
 * закрыто: отложить можно лишь видимое, а в списке остаётся лишь то, что видно
 * до сих пор.
 */
final class FavouritesTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_a_reader_keeps_a_course_a_lesson_and_a_handbook_and_finds_them_in_one_list(): void
    {
        $reader = $this->learner();

        $course = Course::factory()->withLessons(1)->create(['title' => 'Кассовая дисциплина']);
        $lesson = $course->lessons()->firstOrFail();
        $handbook = Regulation::factory()->handbook()->published()->create(['title' => 'Возврат без чека']);

        $this->actingAs($reader);

        $this->postJson("/api/lms/favourites/courses/{$course->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.is_favourite', true);
        $this->postJson("/api/lms/favourites/lessons/{$lesson->getKey()}")->assertOk();
        $this->postJson("/api/lms/favourites/materials/{$handbook->getKey()}")->assertOk();

        $response = $this->getJson('/api/lms/favourites')->assertOk();

        $this->assertSame(
            ['regulation', 'lesson', 'course'],
            array_column($response->json('data'), 'kind'),
            'Отложенное последним должно стоять первым.',
        );

        // Раздел и вид — разное: у справочника вид общий с документом, а раздел
        // свой, и ссылка собирается именно по разделу.
        $handbookRow = collect($response->json('data'))->firstWhere('kind', 'regulation');

        $this->assertSame('handbooks', $handbookRow['section']);
        $this->assertSame('/lms/handbooks/'.$handbook->slug, $handbookRow['url']);

        // Урок без курса — безымянная строка: «Введение» есть в каждом втором курсе.
        $lessonRow = collect($response->json('data'))->firstWhere('kind', 'lesson');

        $this->assertSame('Кассовая дисциплина', $lessonRow['course']);
        $this->assertSame('/lms/'.$course->slug.'/lessons/'.$lesson->slug, $lessonRow['url']);
    }

    public function test_keeping_the_same_material_twice_leaves_one_bookmark(): void
    {
        $reader = $this->learner();
        $course = Course::factory()->published()->create();

        $this->actingAs($reader);

        $this->postJson("/api/lms/favourites/courses/{$course->getKey()}")->assertOk();
        $this->postJson("/api/lms/favourites/courses/{$course->getKey()}")->assertOk();

        $this->assertSame(1, Favourite::query()->count(), 'Звезда — положение, а не счётчик.');
    }

    public function test_a_bookmark_is_dropped_and_the_list_empties(): void
    {
        $reader = $this->learner();
        $document = Regulation::factory()->published()->create();

        $this->actingAs($reader);
        $this->postJson("/api/lms/favourites/materials/{$document->getKey()}")->assertOk();

        $this->deleteJson("/api/lms/favourites/materials/{$document->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.is_favourite', false);

        $this->getJson('/api/lms/favourites')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_dropping_a_bookmark_that_is_not_there_is_not_an_error(): void
    {
        $reader = $this->learner();
        $document = Regulation::factory()->published()->create();

        $this->actingAs($reader);

        $this->deleteJson("/api/lms/favourites/materials/{$document->getKey()}")->assertOk();
    }

    public function test_one_persons_bookmarks_are_invisible_to_another(): void
    {
        $course = Course::factory()->published()->create();
        $keeper = $this->learner();
        $stranger = $this->learner();

        $this->actingAs($keeper);
        $this->postJson("/api/lms/favourites/courses/{$course->getKey()}")->assertOk();

        $this->actingAs($stranger);
        $this->getJson('/api/lms/favourites')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_closed_course_cannot_be_kept_by_someone_who_was_not_let_in(): void
    {
        $course = Course::factory()->closed()->published()->create();

        $this->actingAs($this->learner());

        // 404, а не 403: для постороннего такого курса не существует — то же
        // правило, что и на самой странице курса.
        $this->postJson("/api/lms/favourites/courses/{$course->getKey()}")->assertNotFound();

        $this->assertSame(0, Favourite::query()->count());
    }

    public function test_a_course_closed_after_it_was_kept_leaves_the_list_but_keeps_the_bookmark(): void
    {
        $reader = $this->learner();
        $course = Course::factory()->published()->create();

        $this->actingAs($reader);
        $this->postJson("/api/lms/favourites/courses/{$course->getKey()}")->assertOk();

        $course->update(['visibility' => CourseVisibility::Private]);

        $this->getJson('/api/lms/favourites')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, Favourite::query()->count(), 'Вернут допуск — вернётся и закладка.');

        // Вернули в курс — закладка снова на месте.
        $course->members()->attach($reader->getKey());

        $this->getJson('/api/lms/favourites')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_handbook_is_out_of_reach_for_someone_who_only_reads_documents(): void
    {
        $handbook = Regulation::factory()->handbook()->published()->create();

        $this->actingAs($this->userWith(Permission::ViewDocuments));

        $this->postJson("/api/lms/favourites/materials/{$handbook->getKey()}")->assertNotFound();
    }

    public function test_someone_who_reads_only_handbooks_still_reaches_their_own_list(): void
    {
        $handbook = Regulation::factory()->handbook()->published()->create();
        $reader = $this->userWith(Permission::ViewHandbooks);

        $this->actingAs($reader);

        // Право на маршруте — любое из трёх: иначе продавец, которому открыты
        // одни справочники, не увидел бы собственных закладок.
        $this->postJson("/api/lms/favourites/materials/{$handbook->getKey()}")->assertOk();
        $this->getJson('/api/lms/favourites')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_someone_without_a_single_section_is_not_let_in_at_all(): void
    {
        $this->actingAs($this->userWith(Permission::ViewUsers));

        $this->getJson('/api/lms/favourites')->assertForbidden();
    }

    public function test_a_draft_lesson_cannot_be_kept_by_a_reader_but_can_by_the_one_who_runs_the_course(): void
    {
        $course = Course::factory()->published()->create();
        $module = $course->modules()->create(['title' => 'Начало', 'position' => 0]);
        $draft = Lesson::factory()->draft()->create(['module_id' => $module->getKey()]);

        $this->actingAs($this->learner());
        $this->postJson("/api/lms/favourites/lessons/{$draft->getKey()}")->assertNotFound();

        $this->actingAs($this->editor());
        $this->postJson("/api/lms/favourites/lessons/{$draft->getKey()}")->assertOk();
    }

    public function test_a_discarded_material_leaves_the_list_and_comes_back_with_it(): void
    {
        $reader = $this->learner();
        $document = Regulation::factory()->published()->create();

        $this->actingAs($reader);
        $this->postJson("/api/lms/favourites/materials/{$document->getKey()}")->assertOk();

        $document->delete();

        $this->getJson('/api/lms/favourites')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, Favourite::query()->count(), 'Корзина — не приговор: материал могут вернуть.');

        $document->restore();

        $this->getJson('/api/lms/favourites')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_bookmark_on_something_erased_for_good_is_swept_on_the_next_read(): void
    {
        $reader = $this->learner();
        $document = Regulation::factory()->published()->create();

        $this->actingAs($reader);
        $this->postJson("/api/lms/favourites/materials/{$document->getKey()}")->assertOk();

        // Внешнего ключа у полиморфной связи не бывает, каскада на три таблицы
        // сразу не повесить — поэтому прибирают при чтении списка.
        $document->forceDelete();

        $this->getJson('/api/lms/favourites')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, Favourite::query()->count(), 'Указателю в пустоту в базе делать нечего.');
    }

    public function test_a_lesson_bookmark_is_swept_when_its_course_is_erased_for_good(): void
    {
        $reader = $this->learner();
        $course = Course::factory()->withLessons(1)->create();
        $lesson = $course->lessons()->firstOrFail();

        $this->actingAs($reader);
        $this->postJson("/api/lms/favourites/lessons/{$lesson->getKey()}")->assertOk();

        // Уроки уходят каскадом самой базы, минуя события Eloquent: наблюдатель
        // этого не ловит, и подмести закладку может только чтение списка.
        $course->forceDelete();

        $this->getJson('/api/lms/favourites')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, Favourite::query()->count());
    }

    public function test_the_material_page_says_whether_it_is_kept(): void
    {
        $reader = $this->learner();
        $document = Regulation::factory()->published()->create();

        $this->actingAs($reader);

        $this->getJson('/api/lms/documents/'.$document->slug)
            ->assertOk()
            ->assertJsonPath('data.is_favourite', false);

        $this->postJson("/api/lms/favourites/materials/{$document->getKey()}")->assertOk();

        $this->getJson('/api/lms/documents/'.$document->slug)
            ->assertOk()
            ->assertJsonPath('data.is_favourite', true);
    }

    public function test_the_course_catalogue_says_which_courses_are_kept(): void
    {
        $reader = $this->learner();
        $kept = Course::factory()->published()->create(['title' => 'Отложенный']);
        Course::factory()->published()->create(['title' => 'Обычный']);

        $this->actingAs($reader);
        $this->postJson("/api/lms/favourites/courses/{$kept->getKey()}")->assertOk();

        $rows = collect($this->getJson('/api/lms/courses')->assertOk()->json('data'));

        $this->assertTrue($rows->firstWhere('title', 'Отложенный')['is_favourite']);
        $this->assertFalse($rows->firstWhere('title', 'Обычный')['is_favourite']);
    }

    public function test_an_unknown_kind_in_the_address_is_not_found(): void
    {
        $this->actingAs($this->learner());

        $this->postJson('/api/lms/favourites/news/1')->assertNotFound();
    }
}
