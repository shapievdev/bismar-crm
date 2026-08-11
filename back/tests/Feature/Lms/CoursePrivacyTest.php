<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\CourseVisibility;
use App\Models\Category;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Курс, закрытый от компании.
 *
 * Приватность здесь настоящая: её не отменяет ни право редактировать курсы, ни
 * должность администратора. Открыт приватный курс автору, тем, кого он туда
 * добавил, и суперадминистратору — больше никому.
 */
final class CoursePrivacyTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_a_private_course_stays_out_of_the_catalogue(): void
    {
        $author = $this->author();
        $member = $this->learner();

        $private = $this->privateCourseOf($author, 'Закрытая методика');
        $private->members()->attach($member);

        Course::factory()->published()->create(['title' => 'Общий курс']);

        // Посторонний видит только общее.
        $this->actingAs($this->learner())
            ->getJson(route('lms.courses.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Общий курс');

        foreach ([$author, $member, $this->superAdministrator()] as $allowed) {
            $titles = $this->actingAs($allowed)
                ->getJson(route('lms.courses.index'))
                ->assertOk()
                ->json('data.*.title');

            $this->assertContains('Закрытая методика', $titles);
        }
    }

    public function test_an_outsider_does_not_find_a_private_course_at_all(): void
    {
        $course = $this->privateCourseOf($this->author());
        $lesson = $this->lessonIn($course);

        $outsider = $this->learner();

        // 404, а не 403: закрытый курс для постороннего не существует, и
        // ответ «нельзя» рассказал бы, что он есть.
        $this->actingAs($outsider)
            ->getJson(route('lms.courses.show', $course))
            ->assertNotFound();

        $this->actingAs($outsider)
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertNotFound();

        $this->actingAs($outsider)
            ->postJson(route('lms.enroll', $course))
            ->assertNotFound();
    }

    public function test_someone_admitted_reads_the_course_like_any_other(): void
    {
        $course = $this->privateCourseOf($this->author());
        $lesson = $this->lessonIn($course);

        $member = $this->learner();
        $course->members()->attach($member);

        $this->actingAs($member)
            ->getJson(route('lms.courses.show', $course))
            ->assertOk()
            ->assertJsonPath('data.is_private', true);

        $this->actingAs($member)
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk();
    }

    /**
     * Приватность, которую отменяет должность, приватностью не является.
     *
     * Администратор проходит любую проверку прав через Gate::before — но
     * проверки о конкретном курсе из этого пропуска исключены, иначе закрыть
     * курс от руководства было бы нельзя.
     */
    public function test_an_administrator_does_not_reach_a_private_course(): void
    {
        $course = $this->privateCourseOf($this->author(), 'Закрытая методика');
        $lesson = $this->lessonIn($course);

        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->getJson(route('lms.courses.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($administrator)
            ->getJson(route('lms.courses.show', $course))
            ->assertNotFound();

        $this->actingAs($administrator)
            ->putJson(route('lms.lessons.update', $lesson), ['title' => 'Переписанный урок'])
            ->assertNotFound();
    }

    public function test_a_superadministrator_reaches_every_private_course(): void
    {
        $course = $this->privateCourseOf($this->author());

        $this->actingAs($this->superAdministrator())
            ->getJson(route('lms.courses.show', $course))
            ->assertOk();
    }

    /**
     * Право и доступ складываются, а не заменяют друг друга: править материал
     * приватного курса может тот, у кого есть и право на курсы, и доступ.
     */
    public function test_an_editor_needs_to_be_admitted_before_they_may_edit(): void
    {
        $course = $this->privateCourseOf($this->author());
        $lesson = $this->lessonIn($course);

        $editor = $this->author();

        $this->actingAs($editor)
            ->putJson(route('lms.lessons.update', $lesson), ['title' => 'Переписанный урок'])
            ->assertNotFound();

        $course->members()->attach($editor);

        $this->actingAs($editor)
            ->putJson(route('lms.lessons.update', $lesson), ['title' => 'Переписанный урок'])
            ->assertOk();
    }

    public function test_only_the_author_decides_who_gets_in(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);

        $editor = $this->author();
        $course->members()->attach($editor);

        $newcomer = $this->learner();

        // Впущенный редактор правит материал, но круг допущенных не меняет.
        $this->actingAs($editor)
            ->putJson(route('lms.courses.access.update', $course), ['members' => [$newcomer->id]])
            ->assertForbidden();

        $this->actingAs($this->administrator())
            ->putJson(route('lms.courses.access.update', $course), ['members' => [$newcomer->id]])
            ->assertNotFound();

        $this->actingAs($author)
            ->putJson(route('lms.courses.access.update', $course), ['members' => [$editor->id, $newcomer->id]])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($newcomer)
            ->getJson(route('lms.courses.show', $course))
            ->assertOk();
    }

    /**
     * Доступ автора следует из авторства, а не из списка: строка о нём
     * означала бы, что доступ можно снять, — а его нельзя.
     */
    public function test_the_author_is_never_stored_as_an_admitted_person(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);

        $this->actingAs($author)
            ->putJson(route('lms.courses.access.update', $course), ['members' => [$author->id]])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($author)
            ->getJson(route('lms.courses.show', $course))
            ->assertOk();
    }

    public function test_taking_someone_out_takes_the_course_with_them(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);
        $lesson = $this->lessonIn($course);

        $member = $this->learner();
        $course->members()->attach($member);

        // Открыл урок — значит взялся: курс попадает в «мои материалы».
        $this->actingAs($member)->getJson(route('lms.lessons.show', $lesson))->assertOk();
        $this->actingAs($member)->postJson(route('lms.lessons.complete', $lesson))->assertOk();

        $this->actingAs($member)
            ->getJson(route('lms.my-courses'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($author)
            ->putJson(route('lms.courses.access.update', $course), ['members' => []])
            ->assertOk();

        $this->actingAs($member)
            ->getJson(route('lms.my-courses'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Закрыть курс или открыть — то же решение, что «кого сюда пускать»:
     * иначе впущенный редактор снял бы приватность одним сохранением формы.
     */
    public function test_closing_a_course_is_the_authors_decision(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);

        $editor = $this->author();
        $course->members()->attach($editor);

        $this->actingAs($editor)
            ->putJson(route('lms.courses.update', $course), [
                'title' => $course->title,
                'status' => $course->status->value,
                'visibility' => CourseVisibility::Public->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('visibility');

        $this->actingAs($author)
            ->putJson(route('lms.courses.update', $course), [
                'title' => $course->title,
                'status' => $course->status->value,
                'visibility' => CourseVisibility::Public->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_private', false);

        // Открыли — видно всем.
        $this->actingAs($this->learner())
            ->getJson(route('lms.courses.show', $course))
            ->assertOk();
    }

    /**
     * Правка курса без поля видимости не должна его молча открывать: форма
     * редактора может и не знать о приватности.
     */
    public function test_saving_a_course_without_the_field_leaves_it_closed(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);

        $this->actingAs($author)
            ->putJson(route('lms.courses.update', $course), [
                'title' => 'Новое название',
                'status' => $course->status->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_private', true);
    }

    /** Подсказка поиска не предлагает тех, у кого доступ уже есть. */
    public function test_the_people_picker_skips_the_author_and_the_admitted(): void
    {
        $author = $this->author();
        $author->update(['last_name' => 'Автор', 'first_name' => 'Анна']);

        $course = $this->privateCourseOf($author);

        $member = $this->learner();
        $member->update(['last_name' => 'Петров', 'first_name' => 'Пётр']);
        $course->members()->attach($member);

        $outsider = $this->learner();
        $outsider->update(['last_name' => 'Петрова', 'first_name' => 'Полина']);

        $names = $this->actingAs($author)
            ->getJson(route('lms.courses.access.candidates', ['course' => $course, 'search' => 'петр']))
            ->assertOk()
            ->json('data.*.name');

        // Кириллица ищется наравне с латиницей: базы собраны с C-сортировкой,
        // где ILIKE сам по себе регистр русских букв не складывает.
        $this->assertSame(['Петрова Полина'], $names);
    }

    /**
     * Счётчик раздела — тоже сведения.
     *
     * Показав «2 курса» там, где посторонний найдёт один, каталог сообщил бы о
     * существовании закрытого — и заодно солгал бы о том, что в разделе есть.
     */
    public function test_a_category_counts_only_what_the_reader_may_see(): void
    {
        $category = Category::factory()->create(['name' => 'Продажи']);

        Course::factory()->published()->create(['category_id' => $category->id]);

        Course::factory()->published()->closed()->create([
            'category_id' => $category->id,
            'author_id' => $this->author()->id,
        ]);

        $this->actingAs($this->learner())
            ->getJson(route('lms.categories.index'))
            ->assertOk()
            ->assertJsonPath('data.0.courses_count', 1);

        $this->actingAs($this->superAdministrator())
            ->getJson(route('lms.categories.index'))
            ->assertOk()
            ->assertJsonPath('data.0.courses_count', 2);
    }

    /* ---------- helpers ---------- */

    private function privateCourseOf(User $author, string $title = 'Закрытый курс'): Course
    {
        return Course::factory()->published()->closed()->create([
            'author_id' => $author->id,
            'title' => $title,
        ]);
    }

    private function lessonIn(Course $course): Lesson
    {
        $module = CourseModule::factory()->create(['course_id' => $course->id]);

        return Lesson::factory()->create([
            'module_id' => $module->id,
            'title' => 'Первый урок',
            'content' => 'Содержание закрытого урока.',
        ]);
    }
}
