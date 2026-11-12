<?php

declare(strict_types=1);

namespace App\Support\Exams;

use App\Enums\AttestationStatus;
use App\Models\ExamAssignment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Правила сдачи экзамена: окно попытки, списание просроченного, остаток попыток.
 *
 * Собраны одним местом, потому что спрашивают о них трое — начало попытки,
 * отправка работы и список «мои экзамены», — и правило «что считать потраченной
 * попыткой» у всех троих обязано быть одним. Разойдись оно, человек видел бы в
 * списке «осталась попытка», которой отправка уже не примет.
 */
final readonly class ExamSitting
{
    /**
     * Сколько секунд отправка принимается после конца таймера.
     *
     * Не поблажка, а сетевая честность: браузер отправляет работу в последнюю
     * секунду сам, и отвергать её за то, что запрос шёл дольше нуля, значило бы
     * наказывать за расстояние до сервера. Держать вкладку открытой часами эти
     * секунды не позволяют.
     */
    public const GRACE_SECONDS = 60;

    /**
     * Списывает просроченное окно: попытка начата, время вышло, работы нет.
     *
     * Записывается нулевой попыткой, а не забывается: иначе таймер — не предел,
     * а совет. Закрыл вкладку, не отправив, — начинай заново без следа. Нулевая
     * строка честно тратит попытку и видна в статистике как «время вышло».
     *
     * Зовётся лениво — при следующем действии человека или при показе списка:
     * сторожа, который ходит по базе и дописывает просроченное сам, у
     * приложения нет, и заводить его ради этой строки незачем.
     */
    public function writeOffExpiredWindow(ExamAssignment $assignment, Quiz $quiz): void
    {
        if (! $assignment->hasExpiredWindowAt(Date::now()->subSeconds(self::GRACE_SECONDS))) {
            return;
        }

        QuizAttempt::create([
            'quiz_id' => $quiz->getKey(),
            'user_id' => $assignment->user_id,
            'score' => 0,
            'passed' => false,
            'answers' => [],
            'scores' => [],
            'started_at' => $assignment->started_at,
            // Попытка закончилась, когда вышло время, а не когда это заметили.
            'completed_at' => $assignment->expires_at,
            'review_status' => AttestationStatus::Auto,
        ]);

        $this->closeWindow($assignment);
    }

    /** Окно отправленной или списанной попытки закрывается насовсем. */
    public function closeWindow(ExamAssignment $assignment): void
    {
        $assignment->fill(['started_at' => null, 'expires_at' => null])->save();
    }

    /**
     * Ждёт ли прошлая работа этого человека вердикта проверяющего.
     *
     * Повторяет правило оценки (см. GradeQuizAttempt): вторая работа в очереди
     * не ускорит ответа, и начинать её не даём так же, как не примем.
     */
    public function isAwaitingReview(Quiz $quiz, User $sitter): bool
    {
        if (! $quiz->isAttestation()) {
            return false;
        }

        return $quiz->attempts()
            ->where('user_id', $sitter->getKey())
            ->where('review_status', AttestationStatus::Pending)
            ->exists();
    }
}
