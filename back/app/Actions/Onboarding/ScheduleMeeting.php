<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Exceptions\ConflictException;
use App\Jobs\SendPush;
use App\Models\OnboardingJourneyMeeting;
use App\Support\Push\PushMessage;
use Carbon\CarbonImmutable;

/**
 * Назначает или переносит встречу: дата-время, место, с кем.
 *
 * Перенос — не неудача стажёра: статуса «сорвана» у встречи нет, переносов
 * может быть сколько угодно, и каждый лишь двигает дату и растит счётчик.
 * Напоминание после переноса шлётся заново — reminded_at обнуляется.
 */
final readonly class ScheduleMeeting
{
    /**
     * @param  array{scheduled_at: string, place?: ?string, with_user_id?: ?int}  $attributes
     *
     * @throws ConflictException
     */
    public function handle(OnboardingJourneyMeeting $meeting, array $attributes): OnboardingJourneyMeeting
    {
        if ($meeting->isConfirmed()) {
            throw new ConflictException('Встреча уже проведена — переносить нечего.');
        }

        $newTime = CarbonImmutable::parse($attributes['scheduled_at'])->utc();
        $isReschedule = $meeting->scheduled_at !== null && ! $newTime->equalTo($meeting->scheduled_at);

        $participants = array_key_exists('participant_ids', $attributes)
            ? array_values(array_unique(array_map(intval(...), $attributes['participant_ids'])))
            : (array_key_exists('with_user_id', $attributes) ? array_values(array_filter([$attributes['with_user_id']])) : $meeting->participantIds());
        $meeting->fill([
            'participant_ids' => $participants,
            'scheduled_at' => $newTime,
            'place' => array_key_exists('place', $attributes) ? $attributes['place'] : $meeting->place,
            'with_user_id' => $participants[0] ?? null,
            'reschedules_count' => $meeting->reschedules_count + ($isReschedule ? 1 : 0),
            'reminded_at' => null,
        ])->save();

        $this->tellBothSides($meeting, $isReschedule);

        return $meeting;
    }

    /**
     * О назначенной встрече узнают обе стороны; о переносе — тоже обе. Тег
     * один на встречу: перенос заменяет прежнее уведомление, а не кладёт рядом.
     */
    private function tellBothSides(OnboardingJourneyMeeting $meeting, bool $isReschedule): void
    {
        $meeting->loadMissing('journeyStage.journey');

        $recipients = array_values(array_unique(array_filter([
            (int) $meeting->journeyStage->journey->user_id,
            ...$meeting->participantIds(),
        ])));

        if ($recipients === []) {
            return;
        }

        SendPush::dispatch($recipients, new PushMessage(
            title: $isReschedule ? 'Встреча перенесена' : 'Назначена встреча',
            body: PushMessage::shorten(sprintf(
                '%s — %s',
                $meeting->topic,
                $meeting->scheduled_at?->copy()->timezone(config('onboarding.timezone'))->translatedFormat('j F, H:i') ?? '',
            )),
            url: '/onboarding',
            tag: 'onboarding-meeting-'.$meeting->getKey(),
        ));
    }
}
