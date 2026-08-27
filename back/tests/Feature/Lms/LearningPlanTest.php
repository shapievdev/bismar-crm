<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\Permission;
use App\Models\Course;
use App\Models\LearningPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class LearningPlanTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Тот, кому доверено вести обучение. */
    private function trainer(): User
    {
        return $this->userWith(Permission::ViewCourses, Permission::ManageEnrollments);
    }

    public function test_a_trainer_assigns_courses_in_the_order_they_should_be_taken(): void
    {
        $learner = $this->learner();
        [$first, $second, $third] = Course::factory()->count(3)->published()->create()->all();

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), [
                'courses' => [$second->id, $third->id, $first->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.course.id', $second->id)
            ->assertJsonPath('data.0.position', 1)
            ->assertJsonPath('data.1.course.id', $third->id)
            ->assertJsonPath('data.1.position', 2)
            ->assertJsonPath('data.2.course.id', $first->id)
            ->assertJsonPath('data.2.position', 3);
    }

    /**
     * Список задаётся целиком: чего в нём нет, того нет и в плане.
     */
    public function test_saving_the_plan_replaces_it_rather_than_adding_to_it(): void
    {
        $learner = $this->learner();
        [$kept, $dropped, $added] = Course::factory()->count(3)->published()->create()->all();
        $trainer = $this->trainer();

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), ['courses' => [$kept->id, $dropped->id]])
            ->assertOk();

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), ['courses' => [$added->id, $kept->id]])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.course.id', $added->id)
            ->assertJsonPath('data.1.course.id', $kept->id);

        $this->assertSame(
            [$added->id, $kept->id],
            $learner->planItems()->pluck('course_id')->all(),
        );
    }

    public function test_an_empty_list_clears_the_plan(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->published()->create();
        $trainer = $this->trainer();

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), ['courses' => [$course->id]])
            ->assertOk();

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), ['courses' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(0, $learner->planItems()->count());
    }

    /**
     * Переставить шаг местами — не назначить его заново: кто поставил курс в
     * план, спрашивают именно про того, кто это решил.
     */
    public function test_reordering_keeps_who_assigned_a_step_and_when(): void
    {
        $learner = $this->learner();
        [$first, $second] = Course::factory()->count(2)->published()->create()->all();
        $trainer = $this->trainer();
        $other = $this->trainer();

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), ['courses' => [$first->id, $second->id]])
            ->assertOk();

        $original = LearningPlanItem::query()->where('course_id', $first->id)->sole();

        $this->actingAs($other)
            ->putJson(route('lms.plans.update', $learner), ['courses' => [$second->id, $first->id]])
            ->assertOk();

        $moved = $original->refresh();

        $this->assertSame(2, $moved->position);
        $this->assertSame($trainer->id, $moved->assigned_by_id);
        $this->assertTrue($original->created_at->equalTo($moved->created_at));
    }

    public function test_the_same_course_cannot_be_planned_twice(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->published()->create();

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), ['courses' => [$course->id, $course->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * Назначить можно только то, что видишь сам, — иначе чужой приватный курс
     * попадал бы в план по угаданному номеру.
     */
    public function test_a_course_the_trainer_cannot_see_is_refused(): void
    {
        $learner = $this->learner();
        $secret = Course::factory()->published()->closed()->create();

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), ['courses' => [$secret->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('courses');

        $this->assertSame(0, $learner->planItems()->count());
    }

    public function test_a_deleted_course_cannot_be_planned(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->published()->create();
        $course->delete();

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), ['courses' => [$course->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('courses.0');
    }

    public function test_reading_the_knowledge_base_is_not_enough_to_plan_for_others(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->published()->create();

        $this->actingAs($this->learner())
            ->putJson(route('lms.plans.update', $learner), ['courses' => [$course->id]])
            ->assertForbidden();

        $this->actingAs($this->learner())
            ->getJson(route('lms.plans.show', $learner))
            ->assertForbidden();
    }

    public function test_a_learner_sees_their_own_plan_with_progress(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->withLessons(4)->create();

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), ['courses' => [$course->id]])
            ->assertOk();

        $this->actingAs($learner)
            ->getJson(route('lms.my-plan'))
            ->assertOk()
            ->assertJsonPath('data.0.course.id', $course->id)
            ->assertJsonPath('data.0.position', 1)
            // Назначенного ещё не открывали: честный ноль, а не пустота.
            ->assertJsonPath('data.0.progress', 0)
            ->assertJsonPath('data.0.is_started', false)
            ->assertJsonPath('data.0.is_completed', false);

        $lessons = $course->lessons()->get();

        $this->actingAs($learner)->postJson(route('lms.lessons.complete', $lessons[0]))->assertOk();

        $this->actingAs($learner)
            ->getJson(route('lms.my-plan'))
            ->assertOk()
            ->assertJsonPath('data.0.progress', 25)
            ->assertJsonPath('data.0.is_started', true);
    }

    /**
     * Шаг, за которым для этого человека нет материала, ему не показывают —
     * так же, как не показывают запись на закрытый от него курс.
     */
    public function test_a_step_the_learner_cannot_see_is_left_out_of_their_plan(): void
    {
        $learner = $this->learner();
        $open = Course::factory()->published()->create();
        $closed = Course::factory()->published()->closed()->create();

        // Мимо API: составитель такой курс назначить и не смог бы, а вот
        // закрыть уже назначенный — вполне.
        $learner->planItems()->createMany([
            ['course_id' => $open->id, 'position' => 1],
            ['course_id' => $closed->id, 'position' => 2],
        ]);

        $this->actingAs($learner)
            ->getJson(route('lms.my-plan'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.course.id', $open->id);
    }

    /**
     * Составителю, наоборот, показывают всё — и отмечают, чего сотрудник не
     * увидит: назначить закрытый ему курс можно по недосмотру, и узнать об
     * этом надо на том же экране.
     */
    public function test_the_trainer_is_told_which_steps_the_learner_cannot_see(): void
    {
        $learner = $this->learner();
        $open = Course::factory()->published()->create();
        $closed = Course::factory()->published()->closed()->create();

        $learner->planItems()->createMany([
            ['course_id' => $open->id, 'position' => 1],
            ['course_id' => $closed->id, 'position' => 2],
        ]);

        $this->actingAs($this->trainer())
            ->getJson(route('lms.plans.show', $learner))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_visible_to_learner', true)
            ->assertJsonPath('data.1.is_visible_to_learner', false);
    }

    public function test_people_can_be_searched_when_choosing_whose_plan_to_edit(): void
    {
        User::factory()->create(['last_name' => 'Ёлкина', 'first_name' => 'Вера']);
        User::factory()->create(['last_name' => 'Яковлев', 'first_name' => 'Пётр']);

        $response = $this->actingAs($this->trainer())
            ->getJson(route('lms.plans.people', ['search' => 'ёлкина']))
            ->assertOk();

        // Кириллица ищется без учёта регистра только через ICU — базы собраны
        // с C-сортировкой, см. User::scopeMatching.
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Ёлкина Вера', $response->json('data.0.name'));
    }

    public function test_a_guest_has_no_plan(): void
    {
        $this->getJson(route('lms.my-plan'))->assertUnauthorized();
    }
}
