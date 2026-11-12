<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\OnboardingJourney;
use App\Models\OnboardingJourneyStage;
use App\Models\OnboardingStage;
use App\Models\OnboardingTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Изменения сохраняются в той же транзакции, что и сама операция. */
final class OnboardingObserver
{
    public function created(Model $model): void
    {
        $this->record($model, 'Создано');
    }

    public function updated(Model $model): void
    {
        $this->record($model, 'Изменено');
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'Удалено');
    }

    private function record(Model $model, string $event): void
    {
        $changes = $event === 'Изменено' ? $model->getChanges() : $model->getAttributes();
        unset($changes['updated_at'], $changes['created_at'], $changes['notified_at']);
        if ($changes === []) {
            return;
        }
        $journeyId = match (true) {
            $model instanceof OnboardingJourney => $model->getKey(),
            $model instanceof OnboardingJourneyStage => $model->journey_id,
            isset($model->journey_stage_id) => $model->journeyStage?->journey_id,
            default => null,
        };
        $templateId = match (true) {
            $model instanceof OnboardingTemplate => $model->getKey(),
            $model instanceof OnboardingStage => $model->template_id,
            default => null,
        };
        if ($journeyId === null && $templateId === null) {
            return;
        }
        $before = [];
        foreach (array_keys($changes) as $key) {
            $before[$key] = $model->getRawOriginal($key);
        }
        DB::table('onboarding_activities')->insert([
            'journey_id' => $journeyId, 'template_id' => $templateId,
            'actor_id' => auth()->id(), 'event' => $event,
            'subject' => $model->title ?? $model->topic ?? $model->text ?? ($journeyId ? 'Маршрут' : 'Шаблон'),
            'changes' => json_encode(['before' => $event === 'Изменено' ? $before : null, 'after' => $changes], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
