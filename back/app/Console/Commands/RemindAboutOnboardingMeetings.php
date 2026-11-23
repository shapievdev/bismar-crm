<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\JourneyStatus;
use App\Jobs\SendPush;
use App\Models\OnboardingJourneyMeeting;
use App\Support\Onboarding\RouteSettings;
use App\Support\Push\PushMessage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * Напоминание перед встречей — обеим сторонам.
 *
 * За сколько минут — решает маршрут (meeting_reminder_minutes, снапшот на
 * журнале); пусто — не напоминать. Команда ходит каждые пятнадцать минут,
 * `reminded_at` бережёт от повторов, перенос встречи его обнуляет — о новой
 * дате напомнят заново.
 */
final class RemindAboutOnboardingMeetings extends Command
{
    protected $signature = 'onboarding:remind-meetings {--employee= : Только встречи указанного сотрудника}';

    protected $description = 'Напомнить участникам о близкой встрече стажировки';

    public function handle(): int
    {
        $now = Date::now();
        $reminded = 0;

        $meetings = OnboardingJourneyMeeting::query()
            ->when($this->option('employee'), fn ($query, $id) => $query->whereHas('journeyStage.journey', fn ($journey) => $journey->where('user_id', (int) $id)))
            ->whereNull('confirmed_at')
            ->whereNull('reminded_at')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', $now)
            ->whereHas('journeyStage.journey', fn (Builder $journey) => $journey
                ->where('status', JourneyStatus::Active)
                ->whereNotNull('meeting_reminder_minutes')
                ->whereHas('user', fn ($user) => $user->whereNull('dismissed_at')))
            ->with('journeyStage.journey.user', 'counterpart')
            ->get()
            ->filter(function (OnboardingJourneyMeeting $meeting) use ($now): bool {
                $minutes = $meeting->journeyStage->journey->meeting_reminder_minutes;

                return RouteSettings::of($meeting->journeyStage->journey->settings)['notifications_enabled'] && $minutes !== null
                    && $now->gte($meeting->scheduled_at->copy()->subMinutes($minutes));
            });

        foreach ($meetings as $meeting) {
            $journey = $meeting->journeyStage->journey;

            $recipients = array_values(array_unique(array_filter([
                (int) $journey->user_id,
                ...$meeting->participantIds(),
            ])));

            foreach ($recipients as $recipient) {
                SendPush::dispatch([$recipient], new PushMessage(
                    title: 'Скоро встреча',
                    body: PushMessage::shorten(sprintf(
                        '%s: %s — %s%s',
                        $journey->user?->name ?? 'Стажёр',
                        $meeting->topic,
                        $meeting->scheduled_at->copy()->timezone(config('onboarding.timezone'))->translatedFormat('H:i'),
                        $meeting->place === null ? '' : ', '.$meeting->place,
                    )),
                    url: $recipient === (int) $journey->user_id ? '/onboarding?stage='.$meeting->journey_stage_id : '/onboarding/meetings',
                    tag: 'onboarding-meeting-'.$meeting->getKey(),
                ));

            }
            $meeting->fill(['reminded_at' => $now])->save();
            $reminded++;
        }

        $this->info(sprintf('Напомнено о встречах: %d.', $reminded));

        return self::SUCCESS;
    }
}
