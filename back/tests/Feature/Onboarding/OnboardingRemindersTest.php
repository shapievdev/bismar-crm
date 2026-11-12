<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Jobs\SendPush;
use App\Models\OnboardingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class OnboardingRemindersTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_the_morning_run_tells_the_trainee_and_the_meeting_host(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $host = $this->userWith();
        $trainee = $this->trainee();

        $this->journeyFor($trainee, stages: 2, prepare: function (OnboardingTemplate $template) use ($host): void {
            $template->stages()->get()[1]->meetings()->create([
                'position' => 1, 'topic' => 'Разбор зала', 'format' => 'offline', 'with_user_id' => $host->getKey(),
            ]);
        });

        // Утро дня 2: этап с встречей наступил.
        $this->travelTo(Carbon::parse('2026-11-03 09:00'));
        Queue::fake();

        $this->artisan('onboarding:remind')->assertSuccessful();

        Queue::assertPushed(SendPush::class, function (SendPush $job) use ($trainee): bool {
            return $this->recipientsOf($job) === [$trainee->getKey()];
        });
        Queue::assertPushed(SendPush::class, function (SendPush $job) use ($host): bool {
            return $this->recipientsOf($job) === [$host->getKey()];
        });
    }

    /**
     * @return list<int>
     */
    private function recipientsOf(SendPush $job): array
    {
        return (fn (): array => $this->userIds)->call($job);
    }

    public function test_a_paused_journey_stays_silent(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $trainee = $this->trainee();
        [$journey] = $this->journeyFor($trainee, stages: 2);

        $this->actingAs($this->hr())->postJson(route('onboarding.manage.employees.pause', $trainee))->assertOk();

        $this->travelTo(Carbon::parse('2026-11-03 09:00'));
        Queue::fake();

        $this->artisan('onboarding:remind')->assertSuccessful();

        Queue::assertNotPushed(SendPush::class);
    }

    public function test_the_meeting_reminder_fires_once_inside_its_window(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $host = $this->userWith();
        $trainee = $this->trainee();

        [$journey] = $this->journeyFor(
            $trainee,
            stages: 1,
            templateState: ['meeting_reminder_minutes' => 60],
            prepare: function (OnboardingTemplate $template) use ($host): void {
                $template->stages()->first()->meetings()->create([
                    'position' => 1, 'topic' => 'Знакомство', 'format' => 'online', 'with_user_id' => $host->getKey(),
                ]);
            },
        );

        $meeting = $journey->stages->first()->meetings->first();

        $this->actingAs($this->hr())
            ->putJson(route('onboarding.manage.employees.meetings.schedule', [$trainee, $meeting]), [
                'scheduled_at' => '2026-11-02 12:00',
            ])
            ->assertOk();

        // За два часа — рано: окно напоминания начинается за час.
        $this->travelTo(Carbon::parse('2026-11-02 10:30'));
        Queue::fake();
        $this->artisan('onboarding:remind-meetings')->assertSuccessful();
        Queue::assertNotPushed(SendPush::class);

        // За сорок минут — пора, и ровно один раз.
        $this->travelTo(Carbon::parse('2026-11-02 11:20'));
        $this->artisan('onboarding:remind-meetings')->assertSuccessful();
        Queue::assertPushed(SendPush::class, 2);

        $this->artisan('onboarding:remind-meetings')->assertSuccessful();
        Queue::assertPushed(SendPush::class, 2);
    }
}
