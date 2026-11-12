<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\JourneyStatus;
use App\Exceptions\ConflictException;
use App\Models\OnboardingJourney;
use App\Models\User;
use App\Support\Onboarding\ProfessionalKit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Выдача пункта личного набора с записью «кто и когда».
 * Состав берётся из снимка маршрута, а не из текущего шаблона.
 * Допуск, если он предусмотрен программой, предшествует остальным пунктам.
 */
final readonly class ConfirmGraduation
{
    public const ACCESS = 'access';

    public const TSHIRT = 'tshirt';

    public const BARCODE = 'barcode';

    public function __construct(private RefreshJourneyProgress $refresh) {}

    /**
     * @throws ConflictException
     */
    public function handle(OnboardingJourney $journey, string $what, User $actor): OnboardingJourney
    {
        return DB::transaction(function () use ($journey, $what, $actor): OnboardingJourney {
            $journey = OnboardingJourney::query()->lockForUpdate()->findOrFail($journey->getKey());

            return $this->confirmLocked($journey, $what, $actor);
        });
    }

    private function confirmLocked(OnboardingJourney $journey, string $what, User $actor): OnboardingJourney
    {
        $item = collect(ProfessionalKit::definitions($journey->kit_items))->firstWhere('id', $what);
        if ($item === null) {
            throw new ConflictException('В личном наборе нет такого пункта.');
        }
        $receipts = ProfessionalKit::receipts($journey);
        if (isset($receipts[$what]['confirmed_at'])) {
            throw new ConflictException('Этот пункт уже выдан.');
        }

        if ($journey->status !== JourneyStatus::Active && ! $journey->isCompleted()) {
            throw new ConflictException('Сначала возобновите маршрут.');
        }
        $this->refresh->handle($journey);
        $journey->load('stages.quiz');
        if ($journey->stages->isEmpty() || $journey->stages->contains(fn ($stage) => ! $stage->isCompleted())) {
            throw new ConflictException('Сначала завершите все этапы обучения и сдайте обязательные тесты.');
        }
        $finalStage = $journey->stages->firstWhere('is_final_exam', true)
            ?? $journey->stages->last(fn ($stage) => $stage->quiz !== null);
        if ($item['kind'] === 'admission' && $journey->requires_graduation && ($finalStage?->quiz === null || $finalStage->quiz->questions()->doesntExist())) {
            throw new ConflictException('Для допуска нужен итоговый экзамен с вопросами. Добавьте его в маршрут.');
        }
        $admission = ProfessionalKit::admission($journey->kit_items);
        if ($item['kind'] !== 'admission' && $admission !== null && ! isset($receipts[$admission['id']]['confirmed_at'])) {
            throw new ConflictException('Сначала подтвердите «'.$admission['label'].'».');
        }
        $receipts[$what] = ['confirmed_at' => Date::now()->toIso8601String(), 'confirmed_by' => $actor->getKey(),
            'label' => $item['label'], 'kind' => $item['kind']];
        $attributes = ['kit_receipts' => $receipts];
        if ($item['kind'] === 'admission') {
            $attributes += ['access_confirmed_at' => Date::now(), 'access_confirmed_by' => $actor->getKey()];
        }
        if (in_array($what, [self::TSHIRT, self::BARCODE], true)) {
            $prefix = $what === self::TSHIRT ? 'tshirt_issued' : 'barcode_issued';
            $attributes[$prefix.'_at'] = Date::now();
            $attributes[$prefix.'_by'] = $actor->getKey();
        }
        $journey->fill($attributes)->save();

        // Пересчёт: выпуск бывает последним, чего маршруту не хватало.
        $this->refresh->handle($journey);

        return $journey;
    }
}
