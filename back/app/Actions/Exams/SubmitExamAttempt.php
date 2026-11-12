<?php

declare(strict_types=1);

namespace App\Actions\Exams;

use App\Actions\Lms\GradeQuizAttempt;
use App\Exceptions\ConflictException;
use App\Models\Exam;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\Exams\ExamSitting;
use Illuminate\Support\Facades\Date;

/**
 * Принимает работу: проверяет, что попытка шла и время не вышло, и отдаёт её
 * той же оценке, что проверяет тесты уроков.
 *
 * Своего в оценке экзамена нет ничего — выбор по ключу, письменный ответ по
 * смыслу, таблица человеку (у аттестационного экзамена). Всё своё — вокруг:
 * работа без начатой попытки не принимается (иначе таймер обходится прямым
 * запросом), опоздавшая сверх сетевой поблажки — тоже, и опоздание тратит
 * попытку нулевой строкой, см. ExamSitting.
 */
final readonly class SubmitExamAttempt
{
    public function __construct(
        private ExamSitting $sitting,
        private GradeQuizAttempt $grade,
    ) {}

    /**
     * @param  array<int, list<int>|list<list<string>>|string>  $answers
     *
     * @throws ConflictException
     */
    public function handle(Exam $exam, User $sitter, array $answers): QuizAttempt
    {
        $assignment = $exam->assignments()->where('user_id', $sitter->getKey())->first();

        if ($assignment === null) {
            abort(404);
        }

        $quiz = $exam->loadMissing('quiz.questions.options')->quiz;

        if ($quiz === null) {
            throw new ConflictException('В экзамене пока нет вопросов.');
        }

        if ($assignment->started_at === null) {
            throw new ConflictException('Сначала начните экзамен.');
        }

        if ($assignment->hasExpiredWindowAt(Date::now()->subSeconds(ExamSitting::GRACE_SECONDS))) {
            $this->sitting->writeOffExpiredWindow($assignment, $quiz);

            throw new ConflictException('Время вышло — работа не принята, попытка потрачена.');
        }

        $startedAt = $assignment->started_at;

        $attempt = $this->grade->handle($quiz, $sitter, $answers, null);

        // Начало — на попытке, окно — закрыто: следующая начнётся своим «Начать».
        $attempt->fill(['started_at' => $startedAt])->save();
        $this->sitting->closeWindow($assignment);

        return $attempt;
    }
}
