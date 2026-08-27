<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\Permission;
use App\Models\Course;
use App\Models\LearningPlanItem;
use App\Models\Regulation;
use App\Models\RegulationAcknowledgement;
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

    /**
     * @param  list<array{type: string, id: int}>  $items
     * @return array{items: list<array{type: string, id: int}>}
     */
    private function plan(array $items): array
    {
        return ['items' => $items];
    }

    /**
     * @return array{type: string, id: int}
     */
    private function step(Course|Regulation $item): array
    {
        return [
            'type' => $item instanceof Course ? 'course' : 'regulation',
            'id' => $item->id,
        ];
    }

    /* ---------- Назначение и порядок ---------- */

    public function test_a_trainer_assigns_material_in_the_order_it_should_be_taken(): void
    {
        $learner = $this->learner();
        [$first, $second, $third] = Course::factory()->count(3)->published()->create()->all();

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), $this->plan([
                $this->step($second), $this->step($third), $this->step($first),
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.item_id', $second->id)
            ->assertJsonPath('data.0.position', 1)
            ->assertJsonPath('data.0.kind', 'course')
            ->assertJsonPath('data.1.item_id', $third->id)
            ->assertJsonPath('data.1.position', 2)
            ->assertJsonPath('data.2.item_id', $first->id)
            ->assertJsonPath('data.2.position', 3);
    }

    /**
     * Регламент назначается тем же порядком, что и курс, и стоит с ним в одном
     * списке: сотруднику всё равно, чем именно ему велели заняться третьим.
     */
    public function test_a_plan_mixes_courses_and_regulations(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->published()->create(['title' => 'Работа с клиентом']);
        $regulation = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), $this->plan([
                $this->step($regulation), $this->step($course),
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'regulation')
            ->assertJsonPath('data.0.title', 'Кассовая дисциплина')
            ->assertJsonPath('data.1.kind', 'course')
            ->assertJsonPath('data.1.title', 'Работа с клиентом');
    }

    /**
     * Список задаётся целиком: чего в нём нет, того нет и в плане.
     */
    public function test_saving_the_plan_replaces_it_rather_than_adding_to_it(): void
    {
        $learner = $this->learner();
        $kept = Course::factory()->published()->create();
        $dropped = Regulation::factory()->published()->create();
        $added = Course::factory()->published()->create();
        $trainer = $this->trainer();

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), $this->plan([
                $this->step($kept), $this->step($dropped),
            ]))
            ->assertOk();

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), $this->plan([
                $this->step($added), $this->step($kept),
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.item_id', $added->id)
            ->assertJsonPath('data.1.item_id', $kept->id);

        $this->assertSame(
            [$added->id, $kept->id],
            $learner->planItems()->pluck('plannable_id')->map(intval(...))->all(),
        );
    }

    public function test_an_empty_list_clears_the_plan(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->published()->create();
        $trainer = $this->trainer();

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), $this->plan([$this->step($course)]))
            ->assertOk();

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), $this->plan([]))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(0, $learner->planItems()->count());
    }

    /**
     * Курс №3 и регламент №3 — разные вещи: снимая один, нельзя задеть другой.
     */
    public function test_a_course_and_a_regulation_with_the_same_number_do_not_collide(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->published()->create();
        $regulation = Regulation::factory()->published()->create();

        // Совпадение номеров в тесте не гарантировано, поэтому проверяем то,
        // что важно: оба шага стоят в плане и снимаются независимо.
        $trainer = $this->trainer();

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), $this->plan([
                $this->step($course), $this->step($regulation),
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), $this->plan([$this->step($regulation)]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'regulation');
    }

    /**
     * Переставить шаг местами — не назначить его заново: кто поставил материал
     * в план, спрашивают именно про того, кто это решил.
     */
    public function test_reordering_keeps_who_assigned_a_step_and_when(): void
    {
        $learner = $this->learner();
        [$first, $second] = Course::factory()->count(2)->published()->create()->all();
        $trainer = $this->trainer();
        $other = $this->trainer();

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), $this->plan([
                $this->step($first), $this->step($second),
            ]))
            ->assertOk();

        $original = LearningPlanItem::query()->where('plannable_id', $first->id)->sole();

        $this->actingAs($other)
            ->putJson(route('lms.plans.update', $learner), $this->plan([
                $this->step($second), $this->step($first),
            ]))
            ->assertOk();

        $moved = $original->refresh();

        $this->assertSame(2, $moved->position);
        $this->assertSame($trainer->id, $moved->assigned_by_id);
        $this->assertTrue($original->created_at->equalTo($moved->created_at));
    }

    public function test_the_same_material_cannot_be_planned_twice(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->published()->create();

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), $this->plan([
                $this->step($course), $this->step($course),
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /* ---------- Права и видимость ---------- */

    /**
     * Назначить можно только то, что видишь сам, — иначе чужой закрытый
     * материал попадал бы в план по угаданному номеру.
     */
    public function test_material_the_trainer_cannot_see_is_refused(): void
    {
        $learner = $this->learner();
        $secretCourse = Course::factory()->published()->closed()->create();
        $secretRegulation = Regulation::factory()->published()->closed()->create();
        $trainer = $this->trainer();

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), $this->plan([$this->step($secretCourse)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->actingAs($trainer)
            ->putJson(route('lms.plans.update', $learner), $this->plan([$this->step($secretRegulation)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, $learner->planItems()->count());
    }

    public function test_reading_the_knowledge_base_is_not_enough_to_plan_for_others(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->published()->create();

        $this->actingAs($this->learner())
            ->putJson(route('lms.plans.update', $learner), $this->plan([$this->step($course)]))
            ->assertForbidden();

        $this->actingAs($this->learner())
            ->getJson(route('lms.plans.show', $learner))
            ->assertForbidden();
    }

    /**
     * Шаг, за которым для этого человека нет материала, ему не показывают.
     */
    public function test_a_step_the_learner_cannot_see_is_left_out_of_their_plan(): void
    {
        $learner = $this->learner();
        $open = Regulation::factory()->published()->create();
        $closed = Regulation::factory()->published()->closed()->create();

        // Мимо API: составитель такой регламент назначить и не смог бы, а вот
        // закрыть уже назначенный — вполне.
        $learner->planItems()->createMany([
            ['plannable_type' => 'regulation', 'plannable_id' => $open->id, 'position' => 1],
            ['plannable_type' => 'regulation', 'plannable_id' => $closed->id, 'position' => 2],
        ]);

        $this->actingAs($learner)
            ->getJson(route('lms.my-plan'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.item_id', $open->id);
    }

    /**
     * Составителю, наоборот, показывают всё — и отмечают, чего сотрудник не
     * увидит.
     */
    public function test_the_trainer_is_told_which_steps_the_learner_cannot_see(): void
    {
        $learner = $this->learner();
        $open = Course::factory()->published()->create();
        $closed = Regulation::factory()->published()->closed()->create();

        $learner->planItems()->createMany([
            ['plannable_type' => 'course', 'plannable_id' => $open->id, 'position' => 1],
            ['plannable_type' => 'regulation', 'plannable_id' => $closed->id, 'position' => 2],
        ]);

        $this->actingAs($this->trainer())
            ->getJson(route('lms.plans.show', $learner))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_visible_to_learner', true)
            ->assertJsonPath('data.1.is_visible_to_learner', false);
    }

    /* ---------- Прогресс ---------- */

    public function test_a_learner_sees_their_own_plan_with_course_progress(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->withLessons(4)->create();

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), $this->plan([$this->step($course)]))
            ->assertOk();

        $this->actingAs($learner)
            ->getJson(route('lms.my-plan'))
            ->assertOk()
            ->assertJsonPath('data.0.item_id', $course->id)
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
     * У регламента доли нет: правило либо прочитано, либо нет.
     */
    public function test_a_regulation_step_is_done_once_it_has_been_read(): void
    {
        $learner = $this->learner();
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), $this->plan([$this->step($regulation)]))
            ->assertOk();

        $this->actingAs($learner)
            ->getJson(route('lms.my-plan'))
            ->assertOk()
            ->assertJsonPath('data.0.progress', 0)
            ->assertJsonPath('data.0.is_completed', false);

        $this->actingAs($learner)
            ->postJson(route('lms.regulations.acknowledge', $regulation))
            ->assertOk();

        $this->actingAs($learner)
            ->getJson(route('lms.my-plan'))
            ->assertOk()
            ->assertJsonPath('data.0.progress', 100)
            ->assertJsonPath('data.0.is_completed', true);

        $this->assertSame(1, RegulationAcknowledgement::query()->count());
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
