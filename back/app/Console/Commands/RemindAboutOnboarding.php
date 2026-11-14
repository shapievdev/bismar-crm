<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Onboarding\RefreshJourneyProgress;
use App\Enums\JourneyStatus;
use App\Enums\StageStatus;
use App\Enums\TaskConfirmer;
use App\Jobs\SendPush;
use App\Models\OnboardingJourney;
use App\Models\User;
use App\Support\Onboarding\RouteSettings;
use App\Support\Onboarding\StageRequirements;
use App\Support\Push\PushMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

final class RemindAboutOnboarding extends Command
{
    protected $signature = 'onboarding:remind {--employee= : Только маршрут указанного сотрудника}';

    protected $description = 'Напомнить о наступившем этапе стажёру и ответственным';

    public function handle(RefreshJourneyProgress $refresh, StageRequirements $requirements): int
    {
        $count = 0;
        $journeys = OnboardingJourney::where('status', JourneyStatus::Active)
            ->when($this->option('employee'), fn ($query, $id) => $query->where('user_id', (int) $id))
            ->whereHas('user', fn ($q) => $q->whereNull('dismissed_at'))
            ->with(['user', 'stages.meetings.counterpart', 'stages.tasks'])->get();
        foreach ($journeys as $journey) {
            $refresh->handle($journey);
            if ($journey->status !== JourneyStatus::Active || ! RouteSettings::of($journey->settings)['notifications_enabled']) {
                continue;
            }
            $states = $requirements->of($journey);
            foreach ($journey->stages as $stage) {
                $state = $states[$stage->getKey()] ?? null;
                if ($stage->isCompleted() || $stage->notified_at !== null || $state === null
                    || $state->status === StageStatus::Locked) {
                    continue;
                }
                $meetings = $stage->meetings->whereNull('confirmed_at');
                $ids = collect($stage->responsible_ids ?? [])->merge($meetings->pluck('with_user_id'))
                    ->merge($stage->tasks->where('confirm_by', TaskConfirmer::Responsible)->pluck('responsible_ids')->flatten())
                    ->push($journey->user?->mentor_id)->push($journey->user_id)->filter()->unique();
                $title = ($stage->day_offset === null ? 'Этап '.$stage->position : 'День '.$stage->day_offset).': '.$stage->title;
                $date = Date::now()->timezone(config('onboarding.timezone'))->translatedFormat('j F');
                $meetingNote = $meetings->map(fn ($m) => $m->topic.' — '.($m->counterpart?->name ?? 'ответственный уточняется')
                    .($m->scheduled_at ? ', '.$m->scheduled_at->timezone(config('onboarding.timezone'))->format('d.m H:i') : '')
                    .($m->place ? ', '.$m->place : ''))->implode('; ');
                foreach (User::whereIn('id', $ids)->whereNull('dismissed_at')->get() as $recipient) {
                    $own = (int) $recipient->id === (int) $journey->user_id;
                    $url = $own ? '/onboarding?stage='.$stage->id
                        : ($recipient->can('onboarding.manage') ? '/onboarding/manage/people/'.$journey->user_id.'?stage='.$stage->id : '/onboarding/meetings?stage='.$stage->id);
                    SendPush::dispatch([(int) $recipient->id], new PushMessage(
                        title: $title,
                        body: PushMessage::shorten($journey->user?->name.' · '.$date.'. '.RouteSettings::of($journey->settings)['stage_notification']
                            .($meetingNote ? ' Встречи: '.$meetingNote : ''), 1000),
                        url: $url, tag: 'onboarding-stage-'.$stage->id,
                    ));
                }
                $stage->forceFill(['notified_at' => Date::now()])->save();
                $count++;
            }
        }
        $this->info('Напомнено об этапах: '.$count.'.');

        return self::SUCCESS;
    }
}
