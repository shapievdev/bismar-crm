<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\OnboardingJourney;
use App\Models\OnboardingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class StageProgressTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_the_five_statuses_show_as_the_map_needs_them(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $trainee = $this->trainee();

        $this->journeyFor($trainee, stages: 3, prepare: function (OnboardingTemplate $template): void {
            $second = $template->stages()->get()[1];
            $second->tasks()->create(['position' => 1, 'text' => 'Первое дело', 'confirm_by' => 'self', 'is_required' => true]);
            $second->tasks()->create(['position' => 2, 'text' => 'Второе дело', 'confirm_by' => 'responsible', 'is_required' => true]);

            $this->quizFor($template->stages()->get()[2]);
        });

        // День 2: первый (информационный) этап закрылся сам, второй доступен,
        // третий заперт — его день не настал.
        $this->travelTo(Carbon::parse('2026-11-03 10:00'));

        $response = $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();

        $this->assertSame('completed', $response->json('data.stages.0.status'));
        $this->assertSame('available', $response->json('data.stages.1.status'));
        $this->assertSame('locked', $response->json('data.stages.2.status'));

        // Половина заданий закрыта — «в процессе, 50%».
        $journey = OnboardingJourney::query()->sole();
        $task = $journey->stages->get(1)->tasks()->where('confirm_by', 'self')->sole();

        $this->actingAs($trainee)
            ->postJson(route('onboarding.tasks.submit', $task))
            ->assertOk()
            ->assertJsonPath('data.stages.1.status', 'in_progress')
            ->assertJsonPath('data.stages.1.progress', 50);
    }

    public function test_opening_the_page_is_not_progress(): void
    {
        $trainee = $this->trainee();

        $this->journeyFor($trainee, stages: 1, prepare: function (OnboardingTemplate $template): void {
            $template->stages()->first()->tasks()->create([
                'position' => 1, 'text' => 'Задание', 'confirm_by' => 'self', 'is_required' => true,
            ]);
        });

        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();
        $this->actingAs($trainee)
            ->getJson(route('onboarding.my'))
            ->assertOk()
            ->assertJsonPath('data.stages.0.status', 'available')
            ->assertJsonPath('data.stages.0.progress', 0);

        $this->assertNull(OnboardingJourney::query()->sole()->stages->first()->completed_at);
    }

    public function test_a_stage_with_a_studied_material_closes_lazily(): void
    {
        $trainee = $this->trainee();
        $course = Course::factory()->create();

        [$journey] = $this->journeyFor($trainee, stages: 1, prepare: function (OnboardingTemplate $template) use ($course): void {
            $template->stages()->first()->links()->create([
                'linkable_type' => 'course',
                'linkable_id' => $course->getKey(),
                'position' => 1,
                'is_required' => true,
            ]);
        });

        // Курс пройден в базе знаний — журнал об этом ещё не знает.
        Enrollment::factory()->create([
            'course_id' => $course->getKey(),
            'user_id' => $trainee->getKey(),
            'completed_at' => now(),
        ]);

        $this->assertNull($journey->stages()->first()->completed_at);

        // Первый же взгляд на журнал закрывает этап.
        $this->actingAs($trainee)
            ->getJson(route('onboarding.my'))
            ->assertOk()
            ->assertJsonPath('data.stages.0.status', 'completed');
    }

    public function test_allow_ahead_decides_whether_the_next_stage_waits(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $withTasks = function (OnboardingTemplate $template): void {
            // Оба этапа — в день 1: заперт ли второй, решает только порядок.
            $template->stages()->get()->each(function ($stage): void {
                $stage->fill(['day_offset' => 1])->save();
                $stage->tasks()->create(['position' => 1, 'text' => 'Дело', 'confirm_by' => 'self', 'is_required' => true]);
            });
        };

        $strict = $this->trainee();
        $this->journeyFor($strict, stages: 2, prepare: $withTasks);

        $this->actingAs($strict)
            ->getJson(route('onboarding.my'))
            ->assertOk()
            ->assertJsonPath('data.stages.0.status', 'available')
            ->assertJsonPath('data.stages.1.status', 'locked');

        $free = $this->trainee();
        $this->journeyFor($free, stages: 2, templateState: ['allow_ahead' => true], prepare: $withTasks);

        $this->actingAs($free)
            ->getJson(route('onboarding.my'))
            ->assertOk()
            ->assertJsonPath('data.stages.1.status', 'available');
    }

    public function test_the_overall_progress_counts_completed_stages(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $trainee = $this->trainee();
        $this->journeyFor($trainee, stages: 3);

        // День 2: оба открывшихся информационных этапа закрылись сами — 2 из
        // 3, а третий ждёт своего дня.
        $this->travelTo(Carbon::parse('2026-11-03 10:00'));

        $this->actingAs($trainee)
            ->getJson(route('onboarding.my'))
            ->assertOk()
            ->assertJsonPath('data.progress.completed', 2)
            ->assertJsonPath('data.progress.total', 3)
            ->assertJsonPath('data.progress.percent', 67);
    }

    public function test_a_moved_opening_locks_and_unlocks_the_stage(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $trainee = $this->trainee();

        $this->journeyFor($trainee, stages: 2, prepare: function (OnboardingTemplate $template): void {
            $template->stages()->get()->each(fn ($stage) => $stage->tasks()->create([
                'position' => 1, 'text' => 'Дело', 'confirm_by' => 'self', 'is_required' => true,
            ]));
        });

        $journey = OnboardingJourney::query()->sole();
        $second = $journey->stages->get(1);

        // Уполномоченный сотрудник явно открывает этап, обходя обычный порядок.
        $this->actingAs($this->hr())
            ->putJson(route('onboarding.manage.employees.stages.move', [$trainee, $second]), ['opens_at' => null])
            ->assertOk()
            ->assertJsonPath('data.stages.1.status', 'available');

        // Перенос в будущее запирает датой.
        $this->actingAs($this->hr())
            ->putJson(route('onboarding.manage.employees.stages.move', [$trainee, $second]), ['opens_at' => '2026-11-20'])
            ->assertOk()
            ->assertJsonPath('data.stages.1.status', 'locked');

        $this->assertSame('2026-11-20', $second->refresh()->opens_at->toDateString());
    }
}
