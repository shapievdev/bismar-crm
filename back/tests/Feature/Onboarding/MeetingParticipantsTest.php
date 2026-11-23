<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Actions\Onboarding\ApplyTemplate;
use App\Actions\Onboarding\ConfirmMeeting;
use App\Actions\Onboarding\DuplicateTemplate;
use App\Exceptions\ConflictException;
use App\Jobs\SendPush;
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

final class MeetingParticipantsTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_template_saves_multiple_participants_and_hour_and_snapshot_schedules_them(): void
    {
        Queue::fake();
        $template = OnboardingTemplate::factory()->create();
        $first = $this->userWith();
        $second = $this->userWith();
        $this->actingAs($this->hr())->postJson(route('onboarding.manage.stages.save', $template), [
            'title' => 'Знакомство', 'day_offset' => 1,
            'meetings' => [['topic' => 'Наша команда', 'format' => 'offline', 'participant_ids' => [$first->id, $second->id], 'scheduled_time' => '14:30']],
        ])->assertSuccessful();
        $meeting = $template->stages()->first()->meetings()->sole();
        $this->assertSame([$first->id, $second->id], $meeting->participantIds());
        $this->assertSame('14:30', $meeting->scheduled_time);
        $employee = $this->employee();
        $journey = $this->emptyJourneyFor($employee);
        app(ApplyTemplate::class)->handle($journey, $template, ['anchor' => 'today'], $this->hr());
        $copy = $journey->stages()->first()->meetings()->sole();
        $this->assertSame([$first->id, $second->id], $copy->participantIds());
        $this->assertSame(now()->toDateString().' 14:30', $copy->scheduled_at->timezone(config('onboarding.timezone'))->format('Y-m-d H:i'));
        $meeting->update(['participant_ids' => [], 'with_user_id' => null, 'scheduled_time' => '10:00']);
        $this->assertSame([$first->id, $second->id], $copy->fresh()->participantIds());
    }

    public function test_mentor_can_be_added_alongside_other_participants_without_duplicates(): void
    {
        Queue::fake();
        $mentor = $this->userWith();
        $employee = $this->employee($mentor);
        [$journey] = $this->journeyFor($employee, stages: 1, prepare: function ($template) use ($mentor): void {
            $this->actingAs($this->hr())->postJson(route('onboarding.manage.stages.save', $template), [
                'id' => $template->stages()->first()->id,
                'title' => 'Вводный этап', 'day_offset' => 1,
                'meetings' => [['topic' => 'Знакомство', 'format' => 'offline', 'with_role' => 'mentor', 'participant_ids' => [$mentor->id]]],
            ])->assertSuccessful();
        });
        $this->assertSame([$mentor->id], $journey->stages->first()->meetings()->sole()->participantIds());
    }

    public function test_every_participant_sees_the_invitation_and_one_confirmation_completes_it_for_all(): void
    {
        Queue::fake();
        $first = $this->userWith();
        $second = $this->userWith();
        $employee = $this->employee();
        [$journey] = $this->journeyFor($employee, stages: 1, prepare: fn ($template) => $template->stages()->first()->meetings()->create([
            'position' => 1, 'topic' => 'Общая встреча', 'format' => 'offline', 'participant_ids' => [$first->id, $second->id], 'with_user_id' => $first->id,
        ]));
        $meeting = $journey->stages->first()->meetings()->sole();
        foreach ([$first, $second] as $participant) {
            $this->actingAs($participant)->getJson(route('onboarding.meetings.mine'))->assertOk()->assertJsonPath('data.0.id', $meeting->id)->assertJsonCount(2, 'data.0.participants');
            $this->getJson('/api/onboarding/assigned/stages')->assertOk();
            $this->getJson(route('onboarding.badge'))->assertOk()->assertJsonPath('data.pending_meetings', 1);
        }
        $this->actingAs($this->userWith())->postJson(route('onboarding.meetings.confirm', $meeting))->assertForbidden();
        $this->actingAs($employee)->postJson(route('onboarding.meetings.confirm', $meeting))->assertForbidden();
        $this->actingAs($second)->postJson(route('onboarding.meetings.confirm', $meeting))->assertOk();
        $this->actingAs($first)->getJson(route('onboarding.meetings.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_personal_stage_accepts_datetime_and_multiple_people_and_time_only_move_keeps_them(): void
    {
        Queue::fake();
        $employee = $this->employee();
        $first = $this->userWith();
        $second = $this->userWith();
        $this->actingAs($this->hr())->postJson(route('onboarding.manage.employees.stages.save', $employee), [
            'title' => 'Личный этап', 'meetings' => [[
                'topic' => 'Встреча', 'format' => 'online', 'participant_ids' => [$first->id, $second->id], 'scheduled_at' => '2026-12-01T15:00:00+03:00',
            ]],
        ])->assertSuccessful();
        $meeting = OnboardingJourney::where('user_id', $employee->id)->firstOrFail()->stages()->first()->meetings()->sole();
        $this->assertSame('12:00', $meeting->scheduled_at->utc()->format('H:i'));
        $this->putJson(route('onboarding.manage.employees.meetings.schedule', [$employee, $meeting]), ['scheduled_at' => '2026-12-02T16:00:00+03:00'])->assertSuccessful();
        $this->assertSame([$first->id, $second->id], $meeting->fresh()->participantIds());
        $this->assertSame(1, $meeting->fresh()->reschedules_count);
    }

    public function test_same_employee_can_attend_different_meetings_but_not_twice_in_one(): void
    {
        $host = $this->userWith();
        $template = OnboardingTemplate::factory()->create();
        $data = ['title' => 'Этап', 'day_offset' => 1, 'meetings' => [
            ['topic' => 'Первая', 'format' => 'offline', 'participant_ids' => [$host->id]],
            ['topic' => 'Вторая', 'format' => 'online', 'participant_ids' => [$host->id]],
        ]];
        $this->actingAs($this->hr())->postJson(route('onboarding.manage.stages.save', $template), $data)->assertSuccessful();
        $data['meetings'][0]['participant_ids'][] = $host->id;
        $this->postJson(route('onboarding.manage.stages.save', $template), $data)->assertUnprocessable()->assertJsonValidationErrors('meetings.0.participant_ids');
    }

    public function test_dismissed_and_deleted_people_and_invalid_time_are_rejected(): void
    {
        $gone = User::factory()->dismissed()->create();
        $template = OnboardingTemplate::factory()->create();
        $this->actingAs($this->hr())->postJson(route('onboarding.manage.stages.save', $template), [
            'title' => 'Этап', 'day_offset' => 1, 'meetings' => [['topic' => 'Тест', 'format' => 'offline', 'participant_ids' => [$gone->id], 'scheduled_time' => '25:99']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['meetings.0.participant_ids.0', 'meetings.0.scheduled_time']);
    }

    public function test_reminder_reaches_every_participant_once(): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 10:00:00'));
        $first = $this->userWith();
        $second = $this->userWith();
        [$journey] = $this->journeyFor($this->employee(), stages: 1, prepare: fn ($template) => $template->stages()->first()->meetings()->create([
            'position' => 1, 'topic' => 'Встреча', 'format' => 'offline', 'participant_ids' => [$first->id, $second->id], 'with_user_id' => $first->id,
        ]));
        $journey->update(['meeting_reminder_minutes' => 30]);
        $journey->stages->first()->meetings()->sole()->update(['scheduled_at' => now()->addMinutes(20)]);
        Queue::fake();
        $this->artisan('onboarding:remind-meetings')->assertSuccessful();
        Queue::assertPushed(SendPush::class, 3);
        $this->artisan('onboarding:remind-meetings')->assertSuccessful();
        Queue::assertPushed(SendPush::class, 3);
    }

    public function test_new_template_version_keeps_participants_and_hour(): void
    {
        $host = $this->userWith();
        $template = OnboardingTemplate::factory()->withStages(1)->create();
        $template->stages()->first()->meetings()->create(['position' => 1, 'topic' => 'Встреча', 'format' => 'offline', 'participant_ids' => [$host->id], 'scheduled_time' => '09:45']);
        $copy = app(DuplicateTemplate::class)->handle($template, $this->hr());
        $meeting = $copy->stages()->first()->meetings()->sole();
        $this->assertSame([$host->id], $meeting->participantIds());
        $this->assertSame('09:45', $meeting->scheduled_time);
    }

    public function test_removing_a_participant_revokes_confirmation_and_their_meeting_queue(): void
    {
        Queue::fake();
        $first = $this->userWith();
        $second = $this->userWith();
        $employee = $this->employee();
        [$journey] = $this->journeyFor($employee, stages: 1, prepare: fn ($template) => $template->stages()->first()->meetings()->create([
            'position' => 1, 'topic' => 'Встреча', 'format' => 'offline', 'participant_ids' => [$first->id, $second->id],
        ]));
        $meeting = $journey->stages->first()->meetings()->sole();
        $this->actingAs($this->hr())->putJson(route('onboarding.manage.employees.meetings.schedule', [$employee, $meeting]), [
            'scheduled_at' => now()->addDay()->toIso8601String(), 'participant_ids' => [$first->id],
        ])->assertOk();
        $this->actingAs($second)->getJson(route('onboarding.meetings.mine'))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(route('onboarding.meetings.confirm', $meeting))->assertForbidden();
    }

    public function test_time_without_a_calendar_date_stays_unscheduled_until_the_manager_sets_a_date(): void
    {
        Queue::fake();
        $host = $this->userWith();
        [$journey] = $this->journeyFor($this->employee(), stages: 1, anchor: 'none', prepare: fn ($template) => $template->stages()->first()->meetings()->create([
            'position' => 1, 'topic' => 'Встреча', 'format' => 'offline', 'participant_ids' => [$host->id], 'scheduled_time' => '14:30',
        ]));
        $this->assertNull($journey->stages->first()->meetings()->sole()->scheduled_at);
    }

    public function test_a_second_confirmation_from_a_stale_copy_cannot_overwrite_the_first_host(): void
    {
        Queue::fake();
        $first = $this->userWith();
        $second = $this->userWith();
        [$journey] = $this->journeyFor($this->employee(), stages: 1, prepare: fn ($template) => $template->stages()->first()->meetings()->create([
            'position' => 1, 'topic' => 'Встреча', 'format' => 'offline', 'participant_ids' => [$first->id, $second->id],
        ]));
        $meeting = $journey->stages->first()->meetings()->sole();
        $stale = $meeting->fresh();
        $action = app(ConfirmMeeting::class);
        $action->handle($meeting, $first);
        try {
            $action->handle($stale, $second);
            $this->fail('Repeated confirmation must be refused.');
        } catch (ConflictException) {
            $this->assertSame($first->id, $meeting->fresh()->confirmed_by);
        }
    }
}
