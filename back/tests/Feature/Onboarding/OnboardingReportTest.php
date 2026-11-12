<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\OnboardingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class OnboardingReportTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_the_report_is_closed_without_the_permission(): void
    {
        $this->actingAs($this->userWith())
            ->getJson(route('onboarding.manage.report'))
            ->assertForbidden();
    }

    public function test_empty_routes_are_not_counted_as_started(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        // У этого карта заведена, но пуста — в отчёте его быть не должно.
        $idle = $this->employee();
        $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.employees.show', $idle))
            ->assertOk();

        $walker = $this->employee();
        $this->journeyFor($walker, stages: 2);

        $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.report'))
            ->assertOk()
            ->assertJsonPath('data.started', 1)
            ->assertJsonPath('data.in_progress', 1)
            ->assertJsonPath('data.completed', 0);
    }

    public function test_the_report_counts_finished_routes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        // Информационный маршрут закрывается сам при первом взгляде.
        $finisher = $this->employee();
        $this->journeyFor($finisher, stages: 1);
        $this->actingAs($finisher)->getJson(route('onboarding.my'))->assertOk();

        $walker = $this->employee();
        $this->journeyFor($walker, stages: 3);

        $response = $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.report'))
            ->assertOk();

        $this->assertSame(2, $response->json('data.started'));
        $this->assertSame(1, $response->json('data.completed'));
        $this->assertSame(1, $response->json('data.in_progress'));
        $this->assertEqualsWithDelta(50.0, $response->json('data.completion_rate'), 0.1);
    }

    public function test_the_template_filter_follows_the_stages_pedigree(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $first = $this->employee();
        [, $template] = $this->journeyFor($first, stages: 1);

        $second = $this->employee();
        $other = OnboardingTemplate::factory()->withStages(2)->create(['title' => 'Другой шаблон']);
        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.templates.apply', [$second, $other]), ['anchor' => 'today'])
            ->assertOk();

        $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.report', ['template_id' => $template->getKey()]))
            ->assertOk()
            ->assertJsonPath('data.started', 1);

        $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.report', ['template_id' => $other->getKey()]))
            ->assertOk()
            ->assertJsonPath('data.started', 1);
    }

    public function test_exam_figures_count_first_try_passes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $employee = $this->employee();

        [$journey] = $this->journeyFor($employee, stages: 1, prepare: function (OnboardingTemplate $template): void {
            $this->quizFor($template->stages()->first());
        });

        $stage = $journey->stages->first();

        $this->actingAs($employee)
            ->postJson(route('onboarding.quiz.submit', $stage), [
                'answers' => $this->answersFor($this->journeyQuizOf($stage)),
            ])
            ->assertCreated();

        $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.report'))
            ->assertOk()
            ->assertJsonPath('data.exam_first_try_rate', 100)
            ->assertJsonPath('data.exam_average_attempts', 1);
    }
}
