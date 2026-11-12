<?php

declare(strict_types=1);

namespace App\Support\Onboarding;

use App\Enums\StageStatus;
use App\Models\OnboardingJourney;

/**
 * Кнопка следующего действия: одно «что сейчас сделать» на весь маршрут.
 *
 * Сервер решает, а не экран: правило «первый незакрытый пункт первого
 * доступного этапа» должно совпадать с тем, как считается прогресс, — иначе
 * кнопка позовёт туда, где уже закрыто.
 */
final readonly class NextAction
{
    /**
     * @param  array<int, StageState>  $states
     * @return ?array{kind: string, label: string, url: ?string, journey_stage_id: ?int}
     */
    public function of(OnboardingJourney $journey, array $states): ?array
    {
        if ($journey->isCompleted()) {
            return null;
        }

        foreach ($states as $stageId => $state) {
            if ($state->status === StageStatus::QuizFailed && collect($state->items)->contains(fn ($item) => $item['kind'] === 'quiz' && $item['meta']['attempts_remaining'] === 0)) {
                return [
                    'kind' => 'quiz_failed',
                    'label' => 'Попытки теста кончились — обратитесь к наставнику',
                    'url' => null,
                    'journey_stage_id' => $stageId,
                ];
            }

            if ($state->status !== StageStatus::Available && $state->status !== StageStatus::InProgress && $state->status !== StageStatus::QuizFailed) {
                continue;
            }

            foreach ($state->items as $item) {
                if ($item['done'] || ! $item['is_required']) {
                    continue;
                }

                return [
                    'kind' => $item['kind'],
                    'label' => match ($item['kind']) {
                        'material' => 'Изучите: '.$item['title'],
                        'meeting' => ($item['meta']['awaiting_confirmation'] ?? false) || ($item['meta']['scheduled_at'] ?? null) !== null
                            ? 'Встреча: '.$item['title']
                            : 'Встреча ждёт назначения: '.$item['title'],
                        'task' => ($item['meta']['awaiting_confirmation'] ?? false)
                            ? 'Задание ждёт подтверждения: '.$item['title']
                            : 'Задание: '.$item['title'],
                        'quiz' => ($item['meta']['awaiting_review'] ?? false)
                            ? 'Работа на проверке — дождитесь ответа'
                            : 'Пройдите тест: '.$item['title'],
                        default => $item['title'],
                    },
                    'url' => $item['url'],
                    'journey_stage_id' => $stageId,
                ];
            }
        }

        // Все этапы закрыты, а маршрут ещё не выпущен: дело за выпуском.
        if ($journey->requires_graduation && $journey->stages->every(fn ($stage) => $stage->isCompleted()) && ! $journey->isFullyEquipped()) {
            return [
                'kind' => 'graduation',
                'label' => 'Осталось получить: '.collect(ProfessionalKit::presented($journey))->filter(fn ($item) => $item['is_required'] && $item['confirmed_at'] === null)->pluck('label')->implode(', '),
                'url' => null,
                'journey_stage_id' => null,
            ];
        }

        return null;
    }
}
