<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Actions\Onboarding\ScheduleMeeting;
use App\Models\OnboardingJourney;
use App\Models\OnboardingTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class MeetingsAndTasksTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_a_reschedule_is_not_a_failure(): void
    {
        [$journey, $trainee] = $this->journeyWithMeeting($this->userWith());

        $meeting = $journey->stages->first()->meetings->first();

        $this->actingAs($this->hr())
            ->putJson(route('onboarding.manage.employees.meetings.schedule', [$trainee, $meeting]), [
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertOk();

        $this->actingAs($this->hr())
            ->putJson(route('onboarding.manage.employees.meetings.schedule', [$trainee, $meeting]), [
                'scheduled_at' => now()->addDays(2)->toIso8601String(),
            ])
            ->assertOk();

        $meeting->refresh();

        $this->assertSame(1, $meeting->reschedules_count);
        $this->assertNull($meeting->confirmed_at);

        // Статус этапа не испортился: встреча просто ждёт.
        $this->actingAs($trainee)
            ->getJson(route('onboarding.my'))
            ->assertJsonPath('data.stages.0.status', 'available');
    }

    public function test_the_counterpart_confirms_and_the_stage_closes(): void
    {
        [$journey] = $this->journeyWithMeeting($host = $this->userWith());

        $meeting = $journey->stages->first()->meetings->first();

        // Чужому — отказ: встречу подтверждает тот, кто её проводил.
        $this->actingAs($this->userWith())
            ->postJson(route('onboarding.meetings.confirm', $meeting))
            ->assertForbidden();

        $this->actingAs($host)
            ->postJson(route('onboarding.meetings.confirm', $meeting))
            ->assertOk();

        $this->assertNotNull($journey->stages->first()->refresh()->completed_at);
    }

    public function test_the_hosts_queue_lists_their_meetings(): void
    {
        [$journey] = $this->journeyWithMeeting($host = $this->userWith());

        $this->actingAs($host)
            ->getJson(route('onboarding.meetings.mine'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.id', $journey->user_id);
    }

    public function test_a_self_task_closes_at_once_and_a_responsible_one_waits(): void
    {
        $mentor = $this->userWith();
        $trainee = $this->trainee($mentor);

        [$journey] = $this->journeyFor($trainee, stages: 1, prepare: function (OnboardingTemplate $template): void {
            $stage = $template->stages()->first();
            $stage->tasks()->create(['position' => 1, 'text' => 'Прочитать правила', 'confirm_by' => 'self', 'is_required' => true]);
            $stage->tasks()->create(['position' => 2, 'text' => 'Собрать заказ', 'confirm_by' => 'responsible', 'is_required' => true]);
        });

        $stage = $journey->stages->first();
        [$selfTask, $watchedTask] = [$stage->tasks->get(0), $stage->tasks->get(1)];

        $this->actingAs($trainee)->postJson(route('onboarding.tasks.submit', $selfTask))->assertOk();
        $this->assertNotNull($selfTask->refresh()->confirmed_at);

        $this->actingAs($trainee)->postJson(route('onboarding.tasks.submit', $watchedTask))->assertOk();
        $this->assertNull($watchedTask->refresh()->confirmed_at);
        $this->assertNotNull($watchedTask->submitted_at);

        // Чужому — отказ; наставник подтверждает без единого права в системе.
        $this->actingAs($this->userWith())
            ->postJson(route('onboarding.tasks.confirm', $watchedTask))
            ->assertForbidden();

        $this->actingAs($mentor)
            ->postJson(route('onboarding.tasks.confirm', $watchedTask))
            ->assertOk();

        $this->assertNotNull($watchedTask->refresh()->confirmed_at);
        $this->assertNotNull($stage->refresh()->completed_at);
    }

    public function test_somebody_elses_task_is_not_found(): void
    {
        $trainee = $this->trainee();
        [$journey] = $this->journeyFor($trainee, stages: 1, prepare: function (OnboardingTemplate $template): void {
            $template->stages()->first()->tasks()->create([
                'position' => 1, 'text' => 'Дело', 'confirm_by' => 'self', 'is_required' => true,
            ]);
        });

        $stranger = $this->trainee();
        $this->journeyFor($stranger, stages: 1);

        $task = $journey->stages->first()->tasks->first();

        $this->actingAs($stranger)
            ->postJson(route('onboarding.tasks.submit', $task))
            ->assertNotFound();
    }

    public function test_a_meeting_gets_its_time_together_with_the_stage(): void
    {
        $employee = $this->trainee();
        $host = $this->userWith();
        $at = now()->addDays(2)->setTime(11, 0);

        // Время назначают сразу, вместе с этапом, — отдельного шага нет.
        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.stages.save', $employee), [
                'title' => 'Знакомство',
                'day_offset' => 1,
                'meetings' => [[
                    'topic' => 'Разговор о первой неделе',
                    'format' => 'offline',
                    'place' => 'Торговый зал',
                    'with_user_id' => $host->getKey(),
                    'scheduled_at' => $at->toIso8601String(),
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.stages.0.items.0.meta.scheduled_at', $at->clone()->utc()->toJSON());

        $meeting = OnboardingJourney::query()->where('user_id', $employee->getKey())
            ->firstOrFail()->stages()->first()->meetings()->firstOrFail();

        $this->assertSame(0, $meeting->reschedules_count);
    }

    public function test_the_time_can_be_set_before_the_counterpart_is_known(): void
    {
        $employee = $this->trainee();
        $at = now()->addDays(3)->setTime(9, 30);

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.stages.save', $employee), [
                'title' => 'Знакомство',
                'day_offset' => 1,
                'meetings' => [[
                    'topic' => 'Разговор о первой неделе',
                    'format' => 'online',
                    'scheduled_at' => $at->toIso8601String(),
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.stages.0.items.0.meta.with', null)
            ->assertJsonPath('data.stages.0.items.0.meta.scheduled_at', $at->clone()->utc()->toJSON());
    }

    public function test_moving_the_time_in_the_form_counts_as_a_reschedule(): void
    {
        [$journey, $employee] = $this->journeyWithMeeting($host = $this->userWith());

        $stage = $journey->stages->first();
        $meeting = $stage->meetings->first();
        $meeting->forceFill(['scheduled_at' => now()->addDay()])->save();

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.stages.save', $employee), [
                'id' => $stage->getKey(),
                'title' => $stage->title,
                'day_offset' => $stage->day_offset,
                'meetings' => [[
                    'id' => $meeting->getKey(),
                    'topic' => $meeting->topic,
                    'format' => $meeting->format->value,
                    'with_user_id' => $host->getKey(),
                    'scheduled_at' => now()->addDays(4)->toIso8601String(),
                ]],
            ])
            ->assertOk();

        $this->assertSame(1, $meeting->refresh()->reschedules_count);

        // Та же дата второй раз — не перенос: счётчик стоит на месте.
        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.stages.save', $employee), [
                'id' => $stage->getKey(),
                'title' => $stage->title,
                'day_offset' => $stage->day_offset,
                'meetings' => [[
                    'id' => $meeting->getKey(),
                    'topic' => $meeting->topic,
                    'format' => $meeting->format->value,
                    'with_user_id' => $host->getKey(),
                    'scheduled_at' => $meeting->scheduled_at->toIso8601String(),
                ]],
            ])
            ->assertOk();

        $this->assertSame(1, $meeting->refresh()->reschedules_count);
    }

    public function test_a_held_meeting_keeps_its_time(): void
    {
        [$journey, $employee] = $this->journeyWithMeeting($host = $this->userWith());

        $stage = $journey->stages->first();
        $meeting = $stage->meetings->first();
        $meeting->forceFill(['scheduled_at' => $held = now()->subDay()->startOfHour()])->save();

        $this->actingAs($host)->postJson(route('onboarding.meetings.confirm', $meeting))->assertOk();

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.stages.save', $employee), [
                'id' => $stage->getKey(),
                'title' => $stage->title,
                'day_offset' => $stage->day_offset,
                'meetings' => [[
                    'id' => $meeting->getKey(),
                    'topic' => $meeting->topic,
                    'format' => $meeting->format->value,
                    'with_user_id' => $host->getKey(),
                    'scheduled_at' => now()->addWeek()->toIso8601String(),
                ]],
            ])
            ->assertOk();

        // Проведённую не двигают: её время — уже история.
        $this->assertTrue($meeting->refresh()->scheduled_at->equalTo($held));
    }

    public function test_an_erased_time_leaves_the_meeting_waiting(): void
    {
        [$journey, $employee] = $this->journeyWithMeeting($host = $this->userWith());

        $stage = $journey->stages->first();
        $meeting = $stage->meetings->first();
        $meeting->forceFill(['scheduled_at' => now()->addDay()])->save();

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.stages.save', $employee), [
                'id' => $stage->getKey(),
                'title' => $stage->title,
                'day_offset' => $stage->day_offset,
                'meetings' => [[
                    'id' => $meeting->getKey(),
                    'topic' => $meeting->topic,
                    'format' => $meeting->format->value,
                    'with_user_id' => $host->getKey(),
                    'scheduled_at' => null,
                ]],
            ])
            ->assertOk();

        $this->assertNull($meeting->refresh()->scheduled_at);
        $this->assertSame(0, $meeting->reschedules_count);
    }

    /**
     * Маршрут с одним этапом и встречей с конкретным человеком.
     *
     * @return array{0: OnboardingJourney, 1: User}
     */
    private function journeyWithMeeting(User $host): array
    {
        $trainee = $this->trainee();

        [$journey] = $this->journeyFor($trainee, stages: 1, prepare: function (OnboardingTemplate $template) use ($host): void {
            $template->stages()->first()->meetings()->create([
                'position' => 1,
                'topic' => 'Встреча с менеджером',
                'format' => 'offline',
                'place' => 'Офис',
                'with_user_id' => $host->getKey(),
            ]);
        });

        return [$journey->load('stages.meetings', 'user'), $trainee];
    }

    public function test_meeting_time_with_offset_is_stored_in_utc_and_rescheduling_is_detected(): void
    {
        $employee = $this->trainee();
        $host = $this->userWith();
        [$journey] = $this->journeyFor($employee, stages: 1, prepare: function ($template) use ($host) {
            $template->stages()->first()->meetings()->create(['position' => 1, 'topic' => 'Встреча', 'format' => 'online', 'with_user_id' => $host->id]);
        });
        $meeting = $journey->stages->first()->meetings->first();
        app(ScheduleMeeting::class)->handle($meeting, ['scheduled_at' => '2026-11-12T12:00:00+03:00']);
        $this->assertSame('2026-11-12 09:00:00', $meeting->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        app(ScheduleMeeting::class)->handle($meeting->fresh(), ['scheduled_at' => '2026-11-12T15:00:00+03:00']);
        $this->assertSame('2026-11-12 12:00:00', $meeting->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $meeting->fresh()->reschedules_count);
    }
}
