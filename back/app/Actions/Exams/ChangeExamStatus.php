<?php

declare(strict_types=1);

namespace App\Actions\Exams;

use App\Enums\ExamStatus;
use App\Exceptions\ConflictException;
use App\Models\Exam;

/**
 * Переводит экзамен между черновиком, публикацией и архивом.
 *
 * Отдельно от правки шапки, потому что публикация — решение со своими
 * проверками: опубликованный экзамен виден назначенным и принимает работы, и
 * выпустить его без вопросов или с окном в прошлом значило бы разослать людям
 * обязанность, которую нельзя исполнить.
 *
 * Снятие в черновик и архив разрешены всегда: прятать можно что угодно, и
 * сданные к этой минуте работы никуда не деваются — их читают из результатов.
 */
final readonly class ChangeExamStatus
{
    /**
     * @throws ConflictException
     */
    public function handle(Exam $exam, ExamStatus $status): Exam
    {
        if ($status === ExamStatus::Published) {
            $this->ensureItCanBeSat($exam);
        }

        $exam->fill(['status' => $status])->save();

        return $exam;
    }

    /**
     * @throws ConflictException
     */
    private function ensureItCanBeSat(Exam $exam): void
    {
        $quiz = $exam->loadMissing('quiz')->quiz;

        if ($quiz === null || ! $quiz->questions()->exists()) {
            throw new ConflictException('В экзамене пока нет вопросов — публиковать нечего.');
        }

        if ($exam->closes_at !== null && $exam->closes_at->isPast()) {
            throw new ConflictException('Окно сдачи уже в прошлом — сначала передвиньте его.');
        }
    }
}
