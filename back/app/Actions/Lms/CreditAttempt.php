<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Зачитывает материал по сданной работе.
 *
 * Отдельное действие потому, что путей к зачёту у аттестации два, и оба должны
 * значить одно и то же: вердикт человека (ReviewAttestation) и оценка
 * приложением, когда аттестацию переводят в обычный тест (SaveQuiz). Порознь
 * они разошлись бы — первый закрывал бы урок сам, второй спрашивал бы
 * MaterialDues, — и один и тот же зачёт зависел бы от того, каким путём он
 * пришёл.
 *
 * Решает, что означает зачёт, по-прежнему CreditMaterial: здесь только находят
 * запись на курс, без которой уроку зачитывать некуда.
 */
final readonly class CreditAttempt
{
    public function __construct(private CreditMaterial $credit) {}

    public function handle(QuizAttempt $attempt): void
    {
        $owner = $attempt->loadMissing('quiz.quizzable', 'user')->quiz?->quizzable;
        $learner = $attempt->user;

        if ($owner === null || $learner === null) {
            return;
        }

        $this->credit->handle($owner, $learner, $this->enrollmentOf($owner, $learner));
    }

    /**
     * Запись на курс — она нужна только уроку.
     *
     * Записи может и не быть вовсе: работу сдают и по назначенному плану, и по
     * своей воле — тогда зачитывать нечего, и это не ошибка.
     */
    private function enrollmentOf(Model $owner, User $learner): ?Enrollment
    {
        if (! $owner instanceof Lesson) {
            return null;
        }

        $course = $owner->owningCourse();

        if ($course === null) {
            return null;
        }

        return Enrollment::query()
            ->where('user_id', $learner->getKey())
            ->where('course_id', $course->getKey())
            ->first();
    }
}
