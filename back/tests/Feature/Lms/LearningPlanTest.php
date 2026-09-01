<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\Permission;
use App\Jobs\SendPush;
use App\Models\Category;
use App\Models\Course;
use App\Models\LearningPlanItem;
use App\Models\Regulation;
use App\Models\RegulationAcknowledgement;
use App\Models\User;
use App\Support\Push\PushMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class LearningPlanTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * Тот, кто ведёт обучение: план назначает должность, а не отмеченное право
     * — см. UpdateLearningPlanRequest::authorize().
     */
    private function trainer(): User
    {
        return $this->administrator();
    }

    /** Тот, кому доверено смотреть за обучением, но не распоряжаться им. */
    private function observer(): User
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

    /**
     * Право «вести обучение» открывает чужие планы, но не даёт их менять:
     * смотреть, как идут дела, доверяют шире, чем решать, что кому проходить.
     */
    public function test_the_right_to_manage_enrollments_reads_a_plan_but_does_not_change_it(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->published()->create();
        $observer = $this->observer();

        $this->actingAs($observer)
            ->getJson(route('lms.plans.show', $learner))
            ->assertOk();

        $this->actingAs($observer)
            ->putJson(route('lms.plans.update', $learner), $this->plan([$this->step($course)]))
            ->assertForbidden();

        $this->assertSame(0, $learner->planItems()->count());
    }

    public function test_a_superadministrator_changes_a_plan(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->published()->create();

        $this->actingAs($this->superAdministrator())
            ->putJson(route('lms.plans.update', $learner), $this->plan([$this->step($course)]))
            ->assertOk()
            ->assertJsonPath('data.0.item_id', $course->id);
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

    /* ---------- Что можно назначить ---------- */

    /**
     * Список целиком, а не поиском: план составляют, глядя на то, что есть.
     * Черновиков в нём нет — назначать то, чего сотрудник не откроет, незачем.
     */
    public function test_the_material_list_offers_published_courses_and_regulations_by_category(): void
    {
        $sales = Category::factory()->create(['name' => 'Продажи']);
        $course = Course::factory()->published()->create(['title' => 'Работа с возражениями', 'category_id' => $sales->id]);
        $regulation = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);
        $draft = Course::factory()->create(['title' => 'Ещё пишется']);

        $response = $this->actingAs($this->trainer())
            ->getJson(route('lms.plans.material', $this->learner()))
            ->assertOk();

        $titles = array_column($response->json('data'), 'title');

        $this->assertContains('Работа с возражениями', $titles);
        $this->assertContains('Кассовая дисциплина', $titles);
        $this->assertNotContains('Ещё пишется', $titles);

        $offered = collect($response->json('data'))->keyBy('title');

        $this->assertSame('course', $offered['Работа с возражениями']['kind']);
        $this->assertSame($course->id, $offered['Работа с возражениями']['id']);
        $this->assertSame('Продажи', $offered['Работа с возражениями']['category']);
        $this->assertSame('regulation', $offered['Кассовая дисциплина']['kind']);
        $this->assertSame($regulation->id, $offered['Кассовая дисциплина']['id']);
        // Регламент без раздела приходит с пустой категорией, а не с выдумкой.
        $this->assertNull($offered['Кассовая дисциплина']['category']);
    }

    /** Чужой закрытый курс не всплывает в списке даже названием. */
    public function test_the_material_list_hides_what_the_trainer_cannot_see(): void
    {
        Course::factory()->published()->closed()->create(['title' => 'Закрытый курс']);

        $response = $this->actingAs($this->trainer())
            ->getJson(route('lms.plans.material', $this->learner()))
            ->assertOk();

        $this->assertNotContains('Закрытый курс', array_column($response->json('data'), 'title'));
    }

    /**
     * Назначить закрытое от сотрудника не запрещено — сначала назначить, потом
     * впустить, — но сказать об этом надо до сохранения.
     */
    public function test_the_material_list_marks_what_the_learner_cannot_see(): void
    {
        $trainer = $this->trainer();
        $open = Course::factory()->published()->create(['title' => 'Открытый курс']);
        $mine = Course::factory()->published()->closed()->create([
            'title' => 'Мой закрытый курс',
            'author_id' => $trainer->id,
        ]);

        $offered = collect(
            $this->actingAs($trainer)
                ->getJson(route('lms.plans.material', $this->learner()))
                ->assertOk()
                ->json('data'),
        )->keyBy('title');

        $this->assertTrue($offered['Открытый курс']['is_visible_to_learner']);
        $this->assertFalse($offered['Мой закрытый курс']['is_visible_to_learner']);
        $this->assertSame($mine->id, $offered['Мой закрытый курс']['id']);
        $this->assertSame($open->id, $offered['Открытый курс']['id']);
    }

    public function test_the_material_list_is_closed_to_someone_who_may_not_see_plans(): void
    {
        $this->actingAs($this->learner())
            ->getJson(route('lms.plans.material', $this->learner()))
            ->assertForbidden();
    }

    /* ---------- Сотруднику сообщают об изменении ---------- */

    public function test_the_learner_is_notified_about_a_new_step(): void
    {
        Queue::fake();

        $learner = $this->learner();
        $course = Course::factory()->published()->create(['title' => 'Работа с возражениями']);

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), $this->plan([$this->step($course)]))
            ->assertOk();

        Queue::assertPushed(SendPush::class, function (SendPush $job) use ($learner): bool {
            $message = $this->messageOf($job);

            return $this->recipientsOf($job) === [$learner->id]
                && $message->url === '/lms/plan'
                && str_contains($message->body, 'Работа с возражениями');
        });
    }

    /**
     * Порядок в плане — совет, а не задание: переставленные местами шаги телефон
     * будить не должны.
     */
    public function test_reordering_notifies_nobody(): void
    {
        $learner = $this->learner();
        $first = Course::factory()->published()->create();
        $second = Course::factory()->published()->create();

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), $this->plan([
                $this->step($first),
                $this->step($second),
            ]))
            ->assertOk();

        Queue::fake();

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), $this->plan([
                $this->step($second),
                $this->step($first),
            ]))
            ->assertOk();

        Queue::assertNotPushed(SendPush::class);
    }

    public function test_the_learner_is_notified_when_a_step_is_taken_away(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->published()->create();

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), $this->plan([$this->step($course)]))
            ->assertOk();

        Queue::fake();

        $this->actingAs($this->trainer())
            ->putJson(route('lms.plans.update', $learner), $this->plan([]))
            ->assertOk();

        Queue::assertPushed(SendPush::class, function (SendPush $job) use ($learner): bool {
            return $this->recipientsOf($job) === [$learner->id]
                && str_contains($this->messageOf($job)->body, 'убрал');
        });
    }

    /** Тот, кто правит свой план, только что его и правил. */
    public function test_nobody_is_notified_about_their_own_doing(): void
    {
        Queue::fake();

        $trainer = $this->trainer();

        $this->actingAs($trainer)
            ->putJson(
                route('lms.plans.update', $trainer),
                $this->plan([$this->step(Course::factory()->published()->create())]),
            )
            ->assertOk();

        Queue::assertNotPushed(SendPush::class);
    }

    /**
     * @return list<int>
     */
    private function recipientsOf(SendPush $job): array
    {
        return (fn (): array => $this->userIds)->call($job);
    }

    private function messageOf(SendPush $job): PushMessage
    {
        return (fn (): PushMessage => $this->message)->call($job);
    }
}
