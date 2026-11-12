<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class JourneyLifecycleTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_resume_shifts_the_unopened_schedule_by_the_pause_length(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $trainee = $this->trainee();
        [$journey] = $this->journeyFor($trainee, stages: 3);

        $thirdOpens = $journey->stages->get(2)->opens_at->toDateString();
        $this->assertSame('2026-11-04', $thirdOpens);

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.pause', $trainee))
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');

        // Три дня простоя — будущие этапы уезжают на три дня.
        $this->travelTo(Carbon::parse('2026-11-05 10:00'));

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.resume', $trainee))
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame('2026-11-07', $journey->stages()->get()[2]->opens_at->toDateString());
    }

    public function test_a_finished_route_reopens_when_a_stage_is_added(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $trainee = $this->trainee();
        [$journey] = $this->journeyFor($trainee, stages: 1);

        // Информационный этап закрылся — маршрут завершён.
        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();
        $this->assertSame('completed', $journey->refresh()->status->value);

        // Дописали этап — маршрут снова идёт: дел прибавилось.
        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.stages.save', $trainee), [
                'title' => 'Ещё одно дело',
                'day_offset' => 5,
                'tasks' => [['text' => 'Сделать', 'confirm_by' => 'self']],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonCount(2, 'data.stages');
    }

    public function test_graduation_is_marked_once_per_item(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $trainee = $this->trainee();
        [$journey] = $this->journeyFor($trainee, stages: 1);

        // Информационный этап закрылся — журнал завершён.
        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();
        $this->assertSame('completed', $journey->refresh()->status->value);

        foreach (['access', 'tshirt', 'barcode'] as $what) {
            $this->actingAs($this->hr())
                ->postJson(route('onboarding.manage.employees.graduate', $trainee), ['what' => $what])
                ->assertOk();
        }

        $this->assertTrue($journey->refresh()->isFullyEquipped());

        // Повторная отметка — конфликт, выдача уже случилась.
        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.graduate', $trainee), ['what' => 'tshirt'])
            ->assertConflict();
    }

    public function test_a_finished_journey_still_shows_its_map(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $trainee = $this->trainee();
        [$journey] = $this->journeyFor($trainee, stages: 1);

        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();
        $this->assertSame('completed', $journey->refresh()->status->value);

        $this->actingAs($trainee)
            ->getJson(route('onboarding.my'))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress.percent', 100);
    }
}
