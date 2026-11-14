<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\JourneyStatus;
use App\Enums\StageStatus;
use App\Jobs\SendPush;
use App\Models\OnboardingJourney;
use App\Support\Onboarding\RouteSettings;
use App\Support\Onboarding\StageRequirements;
use App\Support\Push\PushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Сверяет маршрут с фактами: закрывает созревшие этапы и завершает маршрут.
 *
 * Единственное место, где этап получает completed_at, — как CreditMaterial у
 * материалов. Зовётся после каждого действия сотрудника и ответственных,
 * лениво при чтении маршрута и утренней командой: материал, пройденный в базе
 * знаний, маршрут замечает при первом же взгляде на него, а не ждёт события из
 * LMS — осознанная цена за то, что LMS про адаптацию не знает.
 */
final readonly class RefreshJourneyProgress
{
    public function __construct(
        private StageRequirements $requirements,
        private AwardStageAchievements $achievements,
    ) {}

    public function handle(OnboardingJourney $journey): void
    {
        // Завершённый не трогаем: его история дописана, а дописанный этап
        // разбудит маршрут сам (см. SaveJourneyStage, ApplyTemplate).
        if (! $journey->status->isAlive()) {
            return;
        }

        $states = $this->requirements->of($journey);
        $now = Date::now();

        foreach ($journey->stages as $stage) {
            if ($stage->isCompleted() || ! $stage->isOpenAt($now)) {
                continue;
            }

            $states = $this->requirements->of($journey);
            $state = $states[(int) $stage->getKey()] ?? null;

            if ($state === null || $state->status === StageStatus::Locked || ! $state->isSettled()) {
                continue;
            }

            /*
             * Этап закрывается тем днём, когда было сделано последнее дело, а
             * не днём, когда это заметили: ленивый пересчёт не должен сдвигать
             * историю. У этапа без требований дел нет — ему днём закрытия
             * становится день открытия, а если дат в маршруте не держат —
             * сегодняшний.
             */
            $stage->fill([
                'completed_at' => $state->lastDoneAt
                    ?? ($stage->opens_at === null ? $now : CarbonImmutable::parse($stage->opens_at)),
            ])->save();
        }

        /*
         * Значки — после закрытия этапов и до общего итога: за только что
         * закрытый этап полагается свой, и ждать следующего взгляда на карту
         * он не должен. Состояния берутся те же, что считались выше, — второй
         * пересчёт разошёлся бы с первым на дате закрытия.
         */
        $this->achievements->handle($journey, $states);

        $journey->load('stages');

        $allCompleted = $journey->stages->isNotEmpty()
            && $journey->stages->every(fn ($stage): bool => $stage->isCompleted());

        if ($allCompleted && $journey->completed_at === null && $journey->status === JourneyStatus::Active) {
            $journey->fill([
                'status' => JourneyStatus::Completed,
                'completed_at' => Date::now(),
            ])->save();

            $this->congratulate($journey);
        }
    }

    private function congratulate(OnboardingJourney $journey): void
    {
        SendPush::dispatch([(int) $journey->user_id], new PushMessage(
            title: 'Маршрут пройден!',
            body: RouteSettings::of($journey->settings)['completed_message'],
            url: '/onboarding',
            tag: 'onboarding-'.$journey->getKey(),
        ));
    }
}
