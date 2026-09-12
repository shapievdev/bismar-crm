<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\DepartmentRole;
use App\Models\Course;
use App\Models\Department;
use App\Models\Group;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Допуск в закрытый материал группой (решение пользователя 2026-09-12).
 *
 * Складывается с поимённым списком, а не заменяет его. Состав группы читается
 * живьём: ушедший из неё теряет материал тем же вечером — ровно ради этого
 * группу и называют вместо двадцати фамилий.
 */
final class GroupAccessTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /* ---------- Курс ---------- */

    public function test_a_group_opens_a_private_course_to_everyone_in_it(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);

        $insider = $this->learner();
        $outsider = $this->learner();

        $group = Group::factory()->create(['name' => 'Наставники']);
        $group->members()->attach($insider);

        $this->actingAs($author)
            ->putJson(route('lms.courses.access.update', $course), [
                'members' => [],
                'groups' => [$group->id],
                'departments' => [],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.name', 'Наставники')
            ->assertJsonPath('data.groups.0.people_count', 1);

        $this->actingAs($insider)->getJson(route('lms.courses.show', $course))->assertOk();
        $this->actingAs($outsider)->getJson(route('lms.courses.show', $course))->assertNotFound();
    }

    /** Состав читается живьём: вышедший из группы теряет курс. */
    public function test_leaving_the_group_takes_the_course_with_it(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);

        $person = $this->learner();
        $group = Group::factory()->create();
        $group->members()->attach($person);

        $course->memberGroups()->attach($group);

        $this->actingAs($person)->getJson(route('lms.courses.show', $course))->assertOk();

        $group->members()->detach($person);

        $this->actingAs($person)->getJson(route('lms.courses.show', $course))->assertNotFound();
    }

    /** Пришедший в группу открывает то, что ей впустили до него. */
    public function test_joining_the_group_opens_what_it_was_let_into(): void
    {
        $course = $this->privateCourseOf($this->author());

        $group = Group::factory()->create();
        $course->memberGroups()->attach($group);

        $newcomer = $this->learner();

        $this->actingAs($newcomer)->getJson(route('lms.courses.show', $course))->assertNotFound();

        $group->members()->attach($newcomer);

        $this->actingAs($newcomer)->getJson(route('lms.courses.show', $course))->assertOk();
    }

    /** Два способа складываются: названный поимённо остаётся при своём. */
    public function test_the_two_lists_add_up(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);

        $named = $this->learner();
        $inGroup = $this->learner();

        $group = Group::factory()->create();
        $group->members()->attach($inGroup);

        $this->actingAs($author)
            ->putJson(route('lms.courses.access.update', $course), [
                'members' => [$named->id],
                'groups' => [$group->id],
                'departments' => [],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.people')
            ->assertJsonCount(1, 'data.groups');

        foreach ([$named, $inGroup] as $reader) {
            $this->actingAs($reader)->getJson(route('lms.courses.show', $course))->assertOk();
        }
    }

    /** Закрытый курс виден группе и в каталоге, а не только по прямой ссылке. */
    public function test_the_catalogue_shows_what_a_group_was_let_into(): void
    {
        $course = $this->privateCourseOf($this->author());

        $person = $this->learner();
        $group = Group::factory()->create();
        $group->members()->attach($person);

        $this->actingAs($person)
            ->getJson(route('lms.courses.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $course->memberGroups()->attach($group);

        $this->actingAs($person)
            ->getJson(route('lms.courses.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $course->id);
    }

    /** Убрали группу — доступ ушёл вместе с ней. */
    public function test_taking_the_group_out_takes_the_course_with_it(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);

        $person = $this->learner();
        $group = Group::factory()->create();
        $group->members()->attach($person);
        $course->memberGroups()->attach($group);

        $this->actingAs($person)->getJson(route('lms.courses.show', $course))->assertOk();

        $this->actingAs($author)
            ->putJson(route('lms.courses.access.update', $course), ['members' => [], 'groups' => [], 'departments' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.groups');

        $this->actingAs($person)->getJson(route('lms.courses.show', $course))->assertNotFound();
    }

    /**
     * Подсказка поиска отвечает людьми и группами разом: набравший слово не
     * выбирает заранее, кого он ищет.
     */
    public function test_the_picker_answers_with_people_and_groups_at_once(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);

        $this->learner()->update(['last_name' => 'Продажников', 'first_name' => 'Пётр']);

        Group::factory()->create(['name' => 'Отдел продаж']);
        $admitted = Group::factory()->create(['name' => 'Продажи, вторая смена']);
        $course->memberGroups()->attach($admitted);

        $response = $this->actingAs($author)
            ->getJson(route('lms.courses.access.candidates', ['course' => $course, 'search' => 'продаж']))
            ->assertOk();

        $this->assertSame(['Продажников Пётр'], $response->json('data.people.*.name'));

        // Уже впущенная группа не предлагается — доступ у неё есть.
        $this->assertSame(['Отдел продаж'], $response->json('data.groups.*.name'));
    }

    /* ---------- Документы и справочники ---------- */

    public function test_a_group_opens_a_closed_document(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->closed()->create(['author_id' => $author->id]);

        $insider = $this->learner();
        $outsider = $this->learner();

        $group = Group::factory()->create();
        $group->members()->attach($insider);

        $this->actingAs($author)
            ->putJson(route('lms.documents.access.update', $document), [
                'members' => [],
                'groups' => [$group->id],
                'departments' => [],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.groups');

        $this->actingAs($insider)->getJson(route('lms.documents.show', $document))->assertOk();

        // 403, а не 404: закрытость документа проверяет политика, и отказывает
        // она в доступе к существующему — см. RegulationPolicy.
        $this->actingAs($outsider)->getJson(route('lms.documents.show', $document))->assertForbidden();
    }

    public function test_a_group_opens_a_closed_handbook_in_its_own_section(): void
    {
        $author = $this->author();
        $handbook = Regulation::factory()->handbook()->published()->closed()->create(['author_id' => $author->id]);

        $person = $this->learner();
        $group = Group::factory()->create();
        $group->members()->attach($person);
        $handbook->memberGroups()->attach($group);

        $this->actingAs($person)->getJson(route('lms.handbooks.show', $handbook))->assertOk();

        // Раздел документов чужой адрес не открывает и группой тоже.
        $this->actingAs($person)->getJson(route('lms.documents.show', $handbook))->assertNotFound();
    }

    /** Закрытый документ виден группе и в каталоге раздела. */
    public function test_the_document_catalogue_shows_what_a_group_was_let_into(): void
    {
        $document = Regulation::factory()->published()->closed()->create(['author_id' => $this->author()->id]);

        $person = $this->learner();
        $group = Group::factory()->create();
        $group->members()->attach($person);

        $this->actingAs($person)
            ->getJson(route('lms.documents.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $document->memberGroups()->attach($group);

        $this->actingAs($person)
            ->getJson(route('lms.documents.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /* ---------- Кто распоряжается списком ---------- */

    /**
     * Группами распоряжается тот же, кто и людьми: автор и суперадминистратор.
     * Администратору закрытый курс открыт на чтение, но круг допущенных завёл
     * под себя автор — см. CourseAccess::decidesWhoGetsIn.
     */
    public function test_only_the_author_lets_a_group_in(): void
    {
        $course = $this->privateCourseOf($this->author());
        $group = Group::factory()->create();

        $payload = ['members' => [], 'groups' => [$group->id], 'departments' => []];

        // Администратору курс открыт на чтение — отказ ему в распоряжении
        // списком, 403.
        $this->actingAs($this->administrator())
            ->putJson(route('lms.courses.access.update', $course), $payload)
            ->assertForbidden();

        // Чужому редактору и читателю приватного курса не существует вовсе:
        // EnsureCourseAccess отвечает «не найдено» до всякой политики.
        foreach ([$this->author(), $this->learner()] as $stranger) {
            $this->actingAs($stranger)
                ->putJson(route('lms.courses.access.update', $course), $payload)
                ->assertNotFound();
        }

        $this->actingAs($this->superAdministrator())
            ->putJson(route('lms.courses.access.update', $course), $payload)
            ->assertOk();
    }

    /** Несуществующая группа — отказ на входе, а не молча пустой список. */
    public function test_an_unknown_group_is_rejected(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);

        $this->actingAs($author)
            ->putJson(route('lms.courses.access.update', $course), ['members' => [], 'groups' => [999], 'departments' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('groups.0');
    }

    /* ---------- Отдел рядом с группой (2026-09-12) ---------- */

    public function test_a_department_opens_a_private_course_to_everyone_in_it(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);

        [$warehouse, $storeman] = $this->departmentWithPerson('Склад');
        $outsider = $this->learner();

        $this->actingAs($author)
            ->putJson(route('lms.courses.access.update', $course), [
                'members' => [],
                'groups' => [],
                'departments' => [$warehouse->id],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.departments')
            ->assertJsonPath('data.departments.0.name', 'Склад');

        $this->actingAs($storeman)->getJson(route('lms.courses.show', $course))->assertOk();
        $this->actingAs($outsider)->getJson(route('lms.courses.show', $course))->assertNotFound();
    }

    /**
     * Адресуясь отделу, адресуются и всему, что под ним: иначе подотделы
     * пришлось бы перечислять руками, а завтра появится новый и о нём забудут.
     */
    public function test_a_department_carries_its_sub_departments(): void
    {
        $course = $this->privateCourseOf($this->author());

        [$sales] = $this->departmentWithPerson('Продажи');
        $shift = Department::factory()->create(['name' => 'Вторая смена', 'parent_id' => $sales->id]);

        $person = $this->learner();
        $shift->people()->attach($person, ['role' => DepartmentRole::Member->value]);

        $this->actingAs($person)->getJson(route('lms.courses.show', $course))->assertNotFound();

        // Впустили головной отдел — открылось и подотделу.
        $course->memberDepartments()->attach($sales);

        $this->actingAs($person)->getJson(route('lms.courses.show', $course))->assertOk();
    }

    /** Ушёл из отдела — потерял курс: состав читается на каждом обращении. */
    public function test_leaving_the_department_takes_the_course_with_it(): void
    {
        $course = $this->privateCourseOf($this->author());

        [$warehouse, $storeman] = $this->departmentWithPerson('Склад');
        $course->memberDepartments()->attach($warehouse);

        $this->actingAs($storeman)->getJson(route('lms.courses.show', $course))->assertOk();

        $warehouse->people()->detach($storeman);

        $this->actingAs($storeman)->getJson(route('lms.courses.show', $course))->assertNotFound();
    }

    /** Три способа складываются: поимённо, группой и отделом. */
    public function test_all_three_ways_add_up(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);

        $named = $this->learner();

        $group = Group::factory()->create();
        $inGroup = $this->learner();
        $group->members()->attach($inGroup);

        [$warehouse, $storeman] = $this->departmentWithPerson('Склад');

        $this->actingAs($author)
            ->putJson(route('lms.courses.access.update', $course), [
                'members' => [$named->id],
                'groups' => [$group->id],
                'departments' => [$warehouse->id],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.people')
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonCount(1, 'data.departments');

        foreach ([$named, $inGroup, $storeman] as $reader) {
            $this->actingAs($reader)->getJson(route('lms.courses.show', $course))->assertOk();
        }
    }

    /** Закрытый документ открывается отделом так же, как и курс. */
    public function test_a_department_opens_a_closed_document(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->published()->closed()->create(['author_id' => $author->id]);

        [$warehouse, $storeman] = $this->departmentWithPerson('Склад');

        $this->actingAs($author)
            ->putJson(route('lms.documents.access.update', $document), [
                'members' => [],
                'groups' => [],
                'departments' => [$warehouse->id],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.departments');

        $this->actingAs($storeman)->getJson(route('lms.documents.show', $document))->assertOk();
        $this->actingAs($this->learner())->getJson(route('lms.documents.show', $document))->assertForbidden();
    }

    /** Закрытый документ виден отделу и в каталоге, а не только по ссылке. */
    public function test_the_catalogue_shows_what_a_department_was_let_into(): void
    {
        $document = Regulation::factory()->published()->closed()->create(['author_id' => $this->author()->id]);

        [$warehouse, $storeman] = $this->departmentWithPerson('Склад');

        $this->actingAs($storeman)
            ->getJson(route('lms.documents.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $document->memberDepartments()->attach($warehouse);

        $this->actingAs($storeman)
            ->getJson(route('lms.documents.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** Подсказка поиска отвечает и отделами — тем же словом. */
    public function test_the_picker_answers_with_departments_too(): void
    {
        $author = $this->author();
        $course = $this->privateCourseOf($author);

        [$sales] = $this->departmentWithPerson('Отдел продаж');

        $names = $this->actingAs($author)
            ->getJson(route('lms.courses.access.candidates', ['course' => $course, 'search' => 'продаж']))
            ->assertOk()
            ->json('data.departments.*.name');

        $this->assertSame(['Отдел продаж'], $names);

        // Уже впущенный отдел не предлагается — доступ у него есть.
        $course->memberDepartments()->attach($sales);

        $this->actingAs($author)
            ->getJson(route('lms.courses.access.candidates', ['course' => $course, 'search' => 'продаж']))
            ->assertOk()
            ->assertJsonCount(0, 'data.departments');
    }

    /**
     * @return array{0: Department, 1: User}
     */
    private function departmentWithPerson(string $name): array
    {
        $department = Department::factory()->create(['name' => $name]);
        $person = $this->learner();

        $department->people()->attach($person, ['role' => DepartmentRole::Member->value]);

        return [$department, $person];
    }

    private function privateCourseOf(User $author): Course
    {
        return Course::factory()->published()->closed()->create(['author_id' => $author->id]);
    }
}
