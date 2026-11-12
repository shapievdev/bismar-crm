<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Exceptions\ConflictException;
use App\Jobs\SendPush;
use App\Models\OnboardingJourneyStage;
use App\Support\Push\PushMessage;

/**
 * Попытка сверх предела — выход из «тест не сдан».
 *
 * История не переписывается: прежние попытки остаются как были, предел теста
 * для других стажёров не меняется — добавка живёт на этапе журнала этого
 * человека.
 */
final readonly class GrantExtraAttempt
{
    /**
     * @throws ConflictException
     */
    public function handle(OnboardingJourneyStage $stage): OnboardingJourneyStage
    {
        if ($stage->isCompleted()) {
            throw new ConflictException('Этап уже пройден — попытка не нужна.');
        }

        $stage->fill(['extra_attempts' => $stage->extra_attempts + 1])->save();

        $journey = $stage->loadMissing('journey')->journey;

        SendPush::dispatch([(int) $journey->user_id], new PushMessage(
            title: 'Дали ещё одну попытку',
            body: PushMessage::shorten('Этап «'.$stage->title.'»: тест снова открыт — удачи!'),
            url: '/onboarding',
            tag: 'onboarding-attempt-'.$stage->getKey(),
        ));

        return $stage;
    }
}
