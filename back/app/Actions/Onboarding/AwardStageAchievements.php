<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Achievements\AwardAchievement;
use App\Models\OnboardingJourney;
use App\Models\OnboardingJourneyStage;
use App\Models\User;
use App\Support\Onboarding\StageState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Раздаёт значки за закрытые шаги маршрута.
 *
 * Зовётся из RefreshJourneyProgress — там же, где этап получает completed_at,
 * и по той же причине: закрытость шага выводится из фактов, а не хранится, и
 * узнать о ней можно только пересчётом. Своего события у «материал изучен»
 * нет — база знаний про адаптацию не знает (см. RefreshJourneyProgress).
 *
 * Отсюда главное требование: **выдача обязана быть безвредна при повторе**.
 * Пересчёт случается при каждом взгляде на карту, и каждый раз мы проходим по
 * всем закрытым шагам заново. Защищает уникальность пары (достижение,
 * человек): второй раз выдача просто ничего не делает.
 *
 * День получения — день, когда шаг был закрыт, а не когда это заметили: иначе
 * профиль сотрудника пересказывал бы историю его заходов на страницу.
 */
final readonly class AwardStageAchievements
{
    public function __construct(private AwardAchievement $award) {}

    /**
     * @param  array<int, StageState>  $states  состояния этапов от StageRequirements
     */
    public function handle(OnboardingJourney $journey, array $states): void
    {
        $trainee = $journey->loadMissing('user')->user;

        if ($trainee === null) {
            return;
        }

        foreach ($journey->stages as $stage) {
            $state = $states[(int) $stage->getKey()] ?? null;

            if ($state === null) {
                continue;
            }

            $this->awardItems($stage, $state, $trainee);
            $this->awardStage($stage, $trainee);
        }
    }

    /**
     * Значки за дела внутри этапа: материал, встречу, задание, тест.
     *
     * Выдаются по мере закрытия, не дожидаясь конца этапа, — в этом и смысл
     * «дополнительно за материал»: отметка приходит тогда, когда дело сделано.
     *
     * Строки берутся из самого этапа, а не из `meta` состояния: кроме номера
     * значка нужна ещё и сама строка — она ложится в основание выдачи.
     */
    private function awardItems(OnboardingJourneyStage $stage, StageState $state, User $trainee): void
    {
        $done = [];

        foreach ($state->items as $item) {
            if ($item['done']) {
                $done[$item['key']] = $item;
            }
        }

        if ($done === []) {
            return;
        }

        foreach ($stage->links as $link) {
            $this->give($trainee, $link, $link->achievement_id, 'Материал', $done['material-'.$link->getKey()] ?? null);
        }

        foreach ($stage->meetings as $meeting) {
            $this->give($trainee, $meeting, $meeting->achievement_id, 'Встреча', $done['meeting-'.$meeting->getKey()] ?? null);
        }

        foreach ($stage->tasks as $task) {
            $this->give($trainee, $task, $task->achievement_id, 'Задание', $done['task-'.$task->getKey()] ?? null);
        }

        // Тест: значок на этапе, а основание — сам этап. Строки теста у
        // выдачи нет и не нужно — Quiz этапу принадлежит один.
        $quizId = $stage->quiz?->getKey();

        if ($quizId !== null) {
            $this->give($trainee, $stage, $stage->quiz_achievement_id, 'Тест', $done['quiz-'.$quizId] ?? null);
        }
    }

    /**
     * Значок за этап целиком — по его собственному completed_at.
     *
     * Не по `isSettled()` состояния: закрыть этап досрочно может и ведущий
     * адаптацию (MoveStageOpening, ConfirmGraduation), и такой этап тоже
     * пройден. Единственный признак — проставленная дата.
     */
    private function awardStage(OnboardingJourneyStage $stage, User $trainee): void
    {
        if ($stage->achievement_id === null || ! $stage->isCompleted()) {
            return;
        }

        $this->award->handle(
            $trainee,
            $stage->achievement_id,
            sprintf('Этап «%s»', $stage->title),
            $stage,
            CarbonImmutable::parse($stage->completed_at),
        );
    }

    /**
     * @param  ?array{title: string, done_at: ?CarbonImmutable}  $item  закрытое дело или null, если оно ещё не закрыто
     */
    private function give(
        User $trainee,
        Model $source,
        ?int $achievementId,
        string $kind,
        ?array $item,
    ): void {
        if ($achievementId === null || $item === null) {
            return;
        }

        $this->award->handle(
            $trainee,
            $achievementId,
            sprintf('%s «%s»', $kind, $item['title']),
            $source,
            $item['done_at'],
        );
    }
}
