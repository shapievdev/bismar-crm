<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Actions\Lms\SaveQuiz;
use App\Actions\Onboarding\DuplicateTemplate;
use App\Jobs\SendPush;
use App\Models\Achievement;
use App\Models\AchievementAward;
use App\Models\OnboardingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class RouteRequirementsTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_graduation_waits_for_training_and_access_precedes_equipment(): void
    {
        $employee = $this->employee();
        [$journey] = $this->journeyFor($employee, stages: 1, templateState: ['requires_graduation' => true], prepare: function ($template) {
            $this->quizFor($template->stages()->first());
        });
        $hr = $this->hr();
        $url = route('onboarding.manage.employees.graduate', $employee);
        $this->actingAs($hr)->postJson($url, ['what' => 'access'])->assertConflict();
        $stage = $journey->stages->first();
        $this->actingAs($employee)->postJson(route('onboarding.quiz.submit', $stage), ['answers' => $this->answersFor($this->journeyQuizOf($stage))])->assertCreated();
        $this->actingAs($employee)->getJson(route('onboarding.my'))->assertJsonPath('data.progress.percent', 100)->assertJsonPath('data.status', 'active');
        $this->actingAs($hr)->postJson($url, ['what' => 'tshirt'])->assertConflict();
        $this->postJson($url, ['what' => 'access'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson($url, ['what' => 'tshirt'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson($url, ['what' => 'barcode'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame($hr->id, $journey->refresh()->access_confirmed_by);
    }

    public function test_first_failed_attempt_is_visible_and_a_second_attempt_is_available(): void
    {
        $employee = $this->employee();
        [$journey] = $this->journeyFor($employee, stages: 1, prepare: fn ($template) => $this->quizFor($template->stages()->first()));
        $stage = $journey->stages->first();
        $quiz = $this->journeyQuizOf($stage);
        $this->actingAs($employee)->postJson(route('onboarding.quiz.submit', $stage), ['answers' => $this->answersFor($quiz, false)])->assertCreated();
        $response = $this->getJson(route('onboarding.my'))->assertOk()->assertJsonPath('data.stages.0.status', 'quiz_failed');
        $item = collect($response->json('data.stages.0.items'))->firstWhere('kind', 'quiz');
        $this->assertSame(1, $item['meta']['attempts_remaining']);
        $this->assertCount(1, $item['meta']['attempts']);
        $this->postJson(route('onboarding.quiz.submit', $stage), ['answers' => $this->answersFor($quiz)])->assertCreated();
        $this->getJson(route('onboarding.my'))->assertJsonPath('data.status', 'completed');
    }

    public function test_two_attempts_are_the_server_default_for_onboarding_only(): void
    {
        $template = OnboardingTemplate::factory()->withStages(1)->create();
        $quiz = app(SaveQuiz::class)->handle($template->stages()->first(), ['title' => 'Проверка', 'questions' => []]);
        $this->assertSame(2, $quiz->max_attempts);
    }

    public function test_versions_keep_lineage_settings_and_independent_employee_snapshots(): void
    {
        $employee = $this->employee();
        [$journey, $template] = $this->journeyFor($employee, stages: 1, templateState: ['store' => 'Магазин 1', 'settings' => ['passed_message' => 'Отлично!']]);
        $copy = app(DuplicateTemplate::class)->handle($template, $this->hr());
        $another = app(DuplicateTemplate::class)->handle($copy, $this->hr());
        $this->assertSame($template->id, $copy->parent_id);
        $this->assertSame(2, $copy->version);
        $this->assertSame(3, $another->version);
        $template->update(['settings' => ['passed_message' => 'Новое сообщение']]);
        $this->assertSame('Отлично!', $journey->refresh()->settings['passed_message']);
        $this->assertSame(1, $journey->stages->first()->template_version);
        $this->assertSame('Магазин 1', $copy->store);
    }

    public function test_responsible_sees_only_assigned_stages_and_outsider_sees_nothing(): void
    {
        $responsible = $this->userWith();
        $employee = $this->employee();
        [$journey] = $this->journeyFor($employee, stages: 2, prepare: function ($template) use ($responsible) {
            $template->stages()->first()->update(['responsible_ids' => [$responsible->id]]);
        });
        $this->actingAs($responsible)->getJson(route('onboarding.assigned.stages'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.id', $employee->id);
        $this->actingAs($this->userWith())->getJson(route('onboarding.assigned.stages'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_morning_notification_is_idempotent_and_links_to_employee_not_journey(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 09:00'));
        $hr = $this->hr();
        $employee = $this->employee();
        $employee->forceFill(['id' => $employee->id])->save();
        [$journey] = $this->journeyFor($employee, stages: 1, prepare: function ($template) use ($hr) {
            $stage = $template->stages()->first();
            $stage->update(['responsible_ids' => [$hr->id]]);
            $stage->tasks()->create(['position' => 1, 'text' => 'Практика', 'confirm_by' => 'self', 'is_required' => true]);
        });
        Queue::fake();
        $this->artisan('onboarding:remind')->assertSuccessful();
        Queue::assertPushed(SendPush::class, fn ($job) => (fn () => $this->message->url)->call($job) === '/onboarding/manage/people/'.$employee->id.'?stage='.$journey->stages->first()->id);
        Queue::assertPushed(SendPush::class, 2);
        $this->artisan('onboarding:remind')->assertSuccessful();
        Queue::assertPushed(SendPush::class, 2);
    }

    public function test_history_preserves_actor_and_changes_to_stage_dates(): void
    {
        $employee = $this->employee();
        [$journey] = $this->journeyFor($employee, stages: 2);
        $hr = $this->hr();
        $this->actingAs($hr)->putJson(route('onboarding.manage.employees.stages.move', [$employee, $journey->stages->last()]), ['opens_at' => '2027-01-15'])->assertOk();
        $this->assertDatabaseHas('onboarding_activities', ['journey_id' => $journey->id, 'actor_id' => $hr->id, 'event' => 'Изменено']);
        $this->getJson(route('onboarding.manage.employees.show', $employee))->assertOk()->assertJsonStructure(['data' => ['history' => [['event', 'actor_name', 'changes']]]]);
    }

    public function test_paused_journey_and_disabled_notifications_stay_silent(): void
    {
        $employee = $this->employee();
        $this->journeyFor($employee, stages: 1, templateState: ['settings' => ['notifications_enabled' => false]], prepare: function ($template) {
            $template->stages()->first()->tasks()->create(['position' => 1, 'text' => 'Практика', 'confirm_by' => 'self', 'is_required' => true]);
        });
        Queue::fake();
        $this->artisan('onboarding:remind')->assertSuccessful();
        Queue::assertNotPushed(SendPush::class);
    }

    public function test_hr_can_filter_by_exact_version_and_store(): void
    {
        $employee = $this->employee();
        [, $template] = $this->journeyFor($employee, stages: 1, templateState: ['store' => 'Центр']);
        $this->journeyFor($this->employee(), stages: 1, templateState: ['store' => 'Север']);
        $this->actingAs($this->hr())->getJson(route('onboarding.manage.report', ['store' => 'Центр', 'version_id' => $template->id]))->assertOk()->assertJsonPath('data.started', 1);
    }

    public function test_new_program_requires_a_real_final_exam_before_publication(): void
    {
        $response = $this->actingAs($this->hr())->postJson(route('onboarding.manage.templates.store'), ['title' => 'Продавец']);
        $response->assertCreated()->assertJsonPath('data.requires_graduation', true);
        $template = OnboardingTemplate::findOrFail($response->json('data.id'));
        $stage = $template->stages()->create(['position' => 1, 'day_offset' => 1, 'title' => 'Экзамен', 'is_final_exam' => true]);
        $this->postJson(route('onboarding.manage.templates.status', $template), ['status' => 'published'])->assertConflict();
        $this->quizFor($stage);
        $this->postJson(route('onboarding.manage.templates.status', $template), ['status' => 'published'])->assertOk();
    }

    public function test_equipped_badge_is_awarded_once_after_the_last_confirmation(): void
    {
        $employee = $this->employee();
        $badge = Achievement::create(['title' => 'Экипирован и готов к работе!']);
        [$journey] = $this->journeyFor($employee, stages: 1, templateState: ['requires_graduation' => true, 'graduation_achievement_id' => $badge->id], prepare: fn ($t) => $this->quizFor($t->stages()->first()));
        $stage = $journey->stages->first();
        $this->actingAs($employee)->postJson(route('onboarding.quiz.submit', $stage), ['answers' => $this->answersFor($this->journeyQuizOf($stage))])->assertCreated();
        $this->actingAs($this->hr());
        foreach (['access', 'tshirt', 'barcode'] as $what) {
            $this->postJson(route('onboarding.manage.employees.graduate', $employee), ['what' => $what])->assertOk();
        }
        $this->assertDatabaseHas('achievement_user', ['achievement_id' => $badge->id, 'user_id' => $employee->id]);
        $this->actingAs($employee)->getJson(route('onboarding.my'))->assertOk();
        $this->assertSame(1, AchievementAward::where('user_id', $employee->id)->where('achievement_id', $badge->id)->count());
    }

    public function test_stage_responsible_can_confirm_only_their_assigned_tasks(): void
    {
        $responsible = $this->userWith();
        $employee = $this->employee();
        [$journey] = $this->journeyFor($employee, stages: 2, prepare: function ($template) use ($responsible) {
            foreach ($template->stages as $index => $stage) {
                if ($index === 0) {
                    $stage->update(['responsible_ids' => [$responsible->id]]);
                }
                $stage->tasks()->create(['position' => 1, 'text' => 'Практика', 'confirm_by' => 'responsible', 'is_required' => true]);
            }
        });
        $mine = $journey->stages->first()->tasks->first();
        $other = $journey->stages->last()->tasks->first();
        $this->actingAs($responsible)->getJson(route('onboarding.tasks.mine'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson(route('onboarding.badge'))->assertOk()->assertJsonPath('data.pending_meetings', 1);
        $this->postJson(route('onboarding.tasks.confirm', $other))->assertForbidden();
        $this->postJson(route('onboarding.tasks.confirm', $mine))->assertOk();
        $this->getJson(route('onboarding.tasks.mine'))->assertJsonCount(0, 'data');
    }
}
