<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ExamStatus;
use App\Jobs\SendPush;
use App\Models\ExamAssignment;
use App\Support\Push\PushMessage;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * Напоминает о близком сроке экзамена тем, кто его ещё не сдал.
 *
 * Запускается расписанием раз в день (см. routes/console.php). Близкий срок —
 * в ближайшие сутки: личный `due_at` назначения или закрытие окна самого
 * экзамена, что наступит раньше. Уже просроченным не напоминает: звонить после
 * срока — работа руководителя, а не планировщика.
 *
 * Имя уведомления одно на экзамен и человека: второй запуск за те же сутки
 * заменит вчерашнее, а не положит рядом.
 */
final class RemindAboutExams extends Command
{
    protected $signature = 'exams:remind';

    protected $description = 'Напомнить о сроке экзамена тем, кто его ещё не сдал';

    public function handle(): int
    {
        $now = Date::now();
        $soon = $now->copy()->addDay();
        $reminded = 0;

        $assignments = ExamAssignment::query()
            ->whereHas('exam', fn (Builder $exam) => $exam
                ->where('status', ExamStatus::Published)
                // Экзамен, который ещё не открылся, не напоминает о себе:
                // сдать его всё равно нельзя.
                ->where(fn (Builder $open) => $open
                    ->whereNull('opens_at')
                    ->orWhere('opens_at', '<=', $now)))
            ->whereHas('user', fn (Builder $user) => $user->whereNull('dismissed_at'))
            ->with(['exam.quiz', 'user'])
            ->get()
            ->filter(function (ExamAssignment $assignment) use ($now, $soon): bool {
                $deadline = $this->deadlineOf($assignment);

                return $deadline !== null && $deadline->between($now, $soon);
            });

        foreach ($assignments as $assignment) {
            if ($this->alreadyPassed($assignment)) {
                continue;
            }

            $this->remind($assignment);
            $reminded++;
        }

        $this->info(sprintf('Напомнено: %d.', $reminded));

        return self::SUCCESS;
    }

    /**
     * Что наступит раньше: личный срок или закрытие окна. Null — спешить некуда.
     */
    private function deadlineOf(ExamAssignment $assignment): ?CarbonInterface
    {
        $candidates = array_filter([$assignment->due_at, $assignment->exam?->closes_at]);

        return $candidates === [] ? null : min($candidates);
    }

    private function alreadyPassed(ExamAssignment $assignment): bool
    {
        $quiz = $assignment->exam?->quiz;

        return $quiz === null || $quiz->attempts()
            ->where('user_id', $assignment->user_id)
            ->where('passed', true)
            ->exists();
    }

    private function remind(ExamAssignment $assignment): void
    {
        $exam = $assignment->exam;

        SendPush::dispatch([(int) $assignment->user_id], new PushMessage(
            title: 'Экзамен скоро закроется',
            body: PushMessage::shorten(sprintf('%s — сдать до %s.',
                $exam->title,
                $this->deadlineOf($assignment)->translatedFormat('j F, H:i'),
            )),
            url: '/exams/'.$exam->slug,
            tag: sprintf('exam-due-%d-%d', $exam->getKey(), $assignment->user_id),
        ));
    }
}
