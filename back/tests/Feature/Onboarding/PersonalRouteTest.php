<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Jobs\SendPush;
use App\Models\Course;
use App\Models\OnboardingJourney;
use App\Models\OnboardingTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Личный маршрут: у каждого свой, наполняется руками или шаблоном.
 */
final class PersonalRouteTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_every_employee_has_a_route_even_when_empty(): void
    {
        $employee = $this->employee();

        $this->assertSame(0, OnboardingJourney::query()->count());

        $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.employees.show', $employee))
            ->assertOk()
            ->assertJsonPath('data.employee.id', $employee->getKey())
            ->assertJsonCount(0, 'data.stages')
            ->assertJsonPath('data.progress.total', 0);

        // Карта завелась сама — «маршрута нет» не состояние.
        $this->assertSame(1, OnboardingJourney::query()->count());
    }

    public function test_the_employee_sees_their_own_empty_route(): void
    {
        $employee = $this->employee();

        $this->actingAs($employee)
            ->getJson(route('onboarding.my'))
            ->assertOk()
            ->assertJsonCount(0, 'data.stages')
            ->assertJsonPath('data.status', 'active');
    }

    public function test_a_stage_is_added_by_hand_with_its_own_content(): void
    {
        $employee = $this->employee();
        $course = Course::factory()->published()->create(['title' => 'Работа с клиентом']);
        $host = $this->userWith();

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.stages.save', $employee), [
                'title' => 'Первая неделя',
                'day_offset' => 1,
                'links' => [['type' => 'course', 'id' => $course->getKey(), 'day_offset' => 5]],
                'meetings' => [['topic' => 'Знакомство', 'format' => 'offline', 'with_user_id' => $host->getKey()]],
                'tasks' => [['text' => 'Прочитать правила', 'confirm_by' => 'self']],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.stages')
            ->assertJsonPath('data.stages.0.title', 'Первая неделя')
            ->assertJsonPath('data.stages.0.from_template', false)
            // Материал несёт свой день — срок на виду.
            ->assertJsonPath('data.stages.0.items.0.meta.day_offset', 5);
    }

    public function test_a_template_is_appended_rather_than_replacing(): void
    {
        $employee = $this->employee();

        // Свой этап — и поверх него шаблон на три этапа.
        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.stages.save', $employee), [
                'title' => 'Свой этап',
                'day_offset' => 1,
            ])
            ->assertOk();

        $template = OnboardingTemplate::factory()->withStages(3)->create();

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.templates.apply', [$employee, $template]), [
                'anchor' => 'today',
            ])
            ->assertOk()
            ->assertJsonCount(4, 'data.stages')
            ->assertJsonPath('data.stages.0.title', 'Свой этап')
            ->assertJsonPath('data.stages.1.from_template', true);

        // Второй шаблон дописывается следом — маршрут складывается из многих.
        $second = OnboardingTemplate::factory()->withStages(1)->create(['title' => 'Перевод в другой магазин']);

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.templates.apply', [$employee, $second]), [
                'anchor' => 'today',
            ])
            ->assertOk()
            ->assertJsonCount(5, 'data.stages');
    }

    public function test_the_anchor_decides_what_the_days_become(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));

        $template = OnboardingTemplate::factory()->withStages(2)->create();

        // От сегодня: день 1 — сегодня, день 2 — завтра.
        $today = $this->employee();
        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.templates.apply', [$today, $template]), ['anchor' => 'today'])
            ->assertOk();

        $stages = OnboardingJourney::query()->where('user_id', $today->getKey())->sole()->stages;
        $this->assertSame('2026-11-10', $stages[0]->opens_at->toDateString());
        $this->assertSame('2026-11-11', $stages[1]->opens_at->toDateString());

        // От даты приёма.
        $hired = $this->employee();
        $hired->forceFill(['hired_at' => '2026-11-02'])->save();

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.templates.apply', [$hired, $template]), ['anchor' => 'hired'])
            ->assertOk();

        $this->assertSame(
            '2026-11-02',
            OnboardingJourney::query()->where('user_id', $hired->getKey())->sole()->stages[0]->opens_at->toDateString(),
        );

        // От выбранной даты.
        $chosen = $this->employee();
        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.templates.apply', [$chosen, $template]), [
                'anchor' => 'date',
                'date' => '2026-12-01',
            ])
            ->assertOk();

        $this->assertSame(
            '2026-12-01',
            OnboardingJourney::query()->where('user_id', $chosen->getKey())->sole()->stages[0]->opens_at->toDateString(),
        );

        // Без дат: этапы стоят порядком, расписания нет.
        $undated = $this->employee();
        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.templates.apply', [$undated, $template]), ['anchor' => 'none'])
            ->assertOk()
            ->assertJsonPath('data.stages.0.opens_at', null)
            ->assertJsonPath('data.stages.0.day_offset', null)
            ->assertJsonPath('data.current_day', null);
    }

    public function test_the_hired_anchor_needs_a_hire_date(): void
    {
        $template = OnboardingTemplate::factory()->withStages(1)->create();
        $employee = $this->employee();

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.templates.apply', [$employee, $template]), ['anchor' => 'hired'])
            ->assertConflict();
    }

    public function test_the_chosen_date_anchor_needs_a_date(): void
    {
        $template = OnboardingTemplate::factory()->withStages(1)->create();

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.templates.apply', [$this->employee(), $template]), [
                'anchor' => 'date',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_a_copied_stage_lives_on_its_own(): void
    {
        $employee = $this->employee();
        [$journey, $template] = $this->journeyFor($employee, stages: 1, prepare: function (OnboardingTemplate $template): void {
            $template->stages()->first()->tasks()->create([
                'position' => 1, 'text' => 'Дело из шаблона', 'confirm_by' => 'self', 'is_required' => true,
            ]);
        });

        // Правка трафарета копию не достаёт.
        $template->stages()->first()->update(['title' => 'Шаблон переименован']);
        $template->stages()->first()->tasks()->delete();

        $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.employees.show', $employee))
            ->assertOk()
            ->assertJsonPath('data.stages.0.title', 'Этап 1')
            ->assertJsonCount(1, 'data.stages.0.items');

        // И наоборот: правка копии не достаёт трафарет.
        $stageId = $journey->stages->first()->getKey();

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.stages.save', $employee), [
                'id' => $stageId,
                'title' => 'Поправлено человеку',
                'day_offset' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.stages.0.title', 'Поправлено человеку');

        $this->assertSame('Шаблон переименован', $template->stages()->first()->title);
    }

    public function test_a_stage_can_be_removed_until_it_is_passed(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));

        $employee = $this->employee();
        [$journey] = $this->journeyFor($employee, stages: 2);

        $first = $journey->stages->first();

        // Информационный этап закрывается сам — его уже не убрать.
        $this->actingAs($employee)->getJson(route('onboarding.my'))->assertOk();

        $this->actingAs($this->hr())
            ->deleteJson(route('onboarding.manage.employees.stages.destroy', [$employee, $first]))
            ->assertConflict();
    }

    public function test_an_undated_route_opens_stages_by_order(): void
    {
        $employee = $this->employee();

        [$journey] = $this->journeyFor($employee, stages: 2, anchor: 'none', prepare: function (OnboardingTemplate $template): void {
            $template->stages()->get()->each(fn ($stage) => $stage->tasks()->create([
                'position' => 1, 'text' => 'Дело', 'confirm_by' => 'self', 'is_required' => true,
            ]));
        });

        $response = $this->actingAs($employee)->getJson(route('onboarding.my'))->assertOk();

        // Первый открыт — дат нет, держит только очередь; второй ждёт его.
        $this->assertSame('available', $response->json('data.stages.0.status'));
        $this->assertSame('locked', $response->json('data.stages.1.status'));

        $task = $journey->stages->first()->tasks->first();
        $this->actingAs($employee)->postJson(route('onboarding.tasks.submit', $task))->assertOk();

        $this->actingAs($employee)
            ->getJson(route('onboarding.my'))
            ->assertJsonPath('data.stages.0.status', 'completed')
            ->assertJsonPath('data.stages.1.status', 'available');
    }

    public function test_the_employee_is_told_about_new_stages(): void
    {
        Queue::fake();

        $this->journeyFor($this->employee());

        Queue::assertPushed(SendPush::class, 1);
    }

    public function test_the_list_shows_everybody_and_searches(): void
    {
        $withRoute = User::factory()->create(['last_name' => 'Ковалёв', 'first_name' => 'Игорь']);
        $withoutRoute = User::factory()->create(['last_name' => 'Астахова', 'first_name' => 'Марина']);

        $this->journeyFor($withRoute, stages: 1);

        $response = $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.journeys.index'))
            ->assertOk();

        $names = collect($response->json('data'))->pluck('employee.name');

        // В списке и те, у кого маршрут пуст: к ним и приходят его наполнять.
        $this->assertTrue($names->contains(fn (string $name): bool => str_contains($name, 'Ковалёв')));
        $this->assertTrue($names->contains(fn (string $name): bool => str_contains($name, 'Астахова')));

        // Поиск сужает список — и понимает забытую раскладку.
        $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.journeys.index', ['search' => 'Rjd']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.id', $withRoute->getKey());

        $this->assertSame(0, $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.journeys.index', ['search' => 'Аст']))
            ->json('data.0.progress.total'));
    }

    public function test_managing_requires_the_permission(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->userWith())
            ->getJson(route('onboarding.manage.employees.show', $employee))
            ->assertForbidden();
    }
}
