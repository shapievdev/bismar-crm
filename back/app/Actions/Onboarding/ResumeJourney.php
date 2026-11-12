<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\JourneyStatus;
use App\Exceptions\ConflictException;
use App\Models\OnboardingJourney;
use App\Models\OnboardingJourneyMeeting;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Возобновление: расписание сдвигается на длительность простоя.
 *
 * Сдвигаются только этапы, не открытые к моменту паузы: открытое не
 * запирается задним числом, а будущее не должно оказаться «просроченным» за
 * время болезни. Сдвиг круглыми сутками: этап открывается с утра, и перенос
 * на шесть часов тринадцать минут дал бы карту с открытием в обед.
 */
final readonly class ResumeJourney
{
    /**
     * @throws ConflictException
     */
    public function handle(OnboardingJourney $journey): OnboardingJourney
    {
        if ($journey->status !== JourneyStatus::Paused || $journey->paused_at === null) {
            throw new ConflictException('Возобновить можно только приостановленный маршрут.');
        }

        $pausedDays = (int) ceil($journey->paused_at->diffInDays(Date::now(), true));

        DB::transaction(function () use ($journey, $pausedDays): void {
            if ($pausedDays > 0) {
                $journey->stages()
                    ->where('opens_at', '>', $journey->paused_at)
                    ->get()
                    ->each(fn ($stage) => $stage->fill([
                        'opens_at' => $stage->opens_at->addDays($pausedDays),
                    ])->save());
            }

            OnboardingJourneyMeeting::whereHas('journeyStage', fn ($q) => $q->where('journey_id', $journey->id))
                ->whereNull('confirmed_at')->where('scheduled_at', '>', $journey->paused_at)->get()
                ->each(fn ($m) => $m->fill(['scheduled_at' => $m->scheduled_at->addDays($pausedDays), 'reminded_at' => null])->save());

            $journey->fill([
                'status' => JourneyStatus::Active,
                'paused_at' => null,
            ])->save();
        });

        return $journey->load('stages');
    }
}
