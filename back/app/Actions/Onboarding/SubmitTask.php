<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\TaskConfirmer;
use App\Exceptions\ConflictException;
use App\Jobs\SendPush;
use App\Models\OnboardingJourneyTask;
use App\Models\User;
use App\Support\Push\PushMessage;
use Illuminate\Support\Facades\Date;

/**
 * Стажёр отмечает задание сделанным.
 *
 * Самостоятельное задание этим и закрывается. Задание с подтверждением
 * становится заявкой: зачёт поставит ответственный (ConfirmTask), а пуш
 * уходит наставнику — он ближайший, кто видел работу.
 */
final readonly class SubmitTask
{
    public function __construct(private RefreshJourneyProgress $refresh) {}

    /**
     * @throws ConflictException
     */
    public function handle(OnboardingJourneyTask $task, User $trainee): OnboardingJourneyTask
    {
        if ($task->isDone()) {
            throw new ConflictException('Задание уже зачтено.');
        }

        if ($task->submitted_at !== null && $task->confirm_by === TaskConfirmer::Responsible) {
            throw new ConflictException('Отметка уже ждёт подтверждения.');
        }

        $closesItself = $task->confirm_by === TaskConfirmer::Trainee;

        $task->fill([
            'submitted_at' => Date::now(),
            'confirmed_at' => $closesItself ? Date::now() : null,
            'confirmed_by' => $closesItself ? $trainee->getKey() : null,
        ])->save();

        if ($closesItself) {
            $this->refresh->handle($task->loadMissing('journeyStage.journey')->journeyStage->journey);
        } else {
            $this->askMentor($task, $trainee);
        }

        return $task;
    }

    private function askMentor(OnboardingJourneyTask $task, User $trainee): void
    {
        $mentorId = $trainee->mentor_id;

        if ($mentorId === null) {
            return;
        }

        SendPush::dispatch([(int) $mentorId], new PushMessage(
            title: 'Задание ждёт подтверждения',
            body: PushMessage::shorten(sprintf('%s: %s', $trainee->name, $task->text)),
            url: '/onboarding/manage/people/'.$task->loadMissing('journeyStage.journey')->journeyStage->journey->getKey(),
            tag: 'onboarding-task-'.$task->getKey(),
        ));
    }
}
