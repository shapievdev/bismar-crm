<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\AttestationStatus;
use App\Enums\QuestionType;
use App\Enums\QuizKind;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Тест, который проверяет человек.
 *
 * Приложению есть чем мерить выбор варианта и письменный ответ, но не работу:
 * заполненную таблицу, расчёт, разбор случая. Зачесть такое по заполненности —
 * вежливая форма отказа от проверки, поэтому у аттестации есть адресат, а у
 * попытки — состояние между отправкой и вердиктом.
 */
final class AttestationTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /* ---------- Что можно спросить ---------- */

    /**
     * Таблица только на аттестации: обычный тест зачёл бы её по
     * заполненности, то есть ни по чему.
     */
    public function test_a_table_question_is_refused_in_an_ordinary_quiz(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $lesson), $this->payload(kind: QuizKind::Standard, table: true))
            ->assertJsonValidationErrorFor('questions.0.type');
    }

    public function test_a_table_question_is_allowed_in_an_attestation(): void
    {
        $lesson = $this->lesson();
        $examiner = $this->learner();

        $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $lesson), $this->payload(
                kind: QuizKind::Attestation,
                table: true,
                examiner: $examiner,
            ))
            ->assertCreated()
            ->assertJsonPath('data.kind', QuizKind::Attestation->value)
            ->assertJsonPath('data.examiner.id', $examiner->getKey());
    }

    /** У аттестации должен быть адресат: очередь без хозяина никто не разберёт. */
    public function test_an_attestation_without_an_examiner_is_refused(): void
    {
        $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $this->lesson()), $this->payload(kind: QuizKind::Attestation))
            ->assertJsonValidationErrorFor('examiner_id');
    }

    /** У обычного теста проверять нечего — назначенный ждал бы работ впустую. */
    public function test_an_ordinary_quiz_refuses_an_examiner(): void
    {
        $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $this->lesson()), $this->payload(
                kind: QuizKind::Standard,
                examiner: $this->learner(),
            ))
            ->assertJsonValidationErrorFor('examiner_id');
    }

    /* ---------- Что происходит при сдаче ---------- */

    /**
     * Работа уходит человеку, а не оценивается на месте: урок не зачитывается,
     * даже когда все выбранные варианты верны.
     */
    public function test_a_submitted_attestation_waits_for_a_person(): void
    {
        [$lesson, $quiz] = $this->attestation();
        $learner = $this->learner();

        $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated()
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.review_status', AttestationStatus::Pending->value)
            // Счёт посчитан — он справка проверяющему, а не приговор.
            ->assertJsonPath('data.score', 100);

        $this->assertSame(0, LessonCompletion::query()->count(), 'Урок зачтён без проверки.');
    }

    /** Одна работа на проверке за раз: вторая только удвоит очередь. */
    public function test_a_second_submission_waits_for_the_first_verdict(): void
    {
        [$lesson, $quiz] = $this->attestation();
        $learner = $this->learner();

        $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated();

        $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->correctAnswers($quiz)])
            ->assertConflict();
    }

    /* ---------- Очередь проверяющего ---------- */

    public function test_the_examiner_sees_what_was_submitted_to_them(): void
    {
        [$lesson, $quiz, $examiner] = $this->attestation();
        $learner = $this->learner();

        $this->submit($learner, $lesson, $quiz);

        $this->actingAs($examiner)
            ->getJson(route('lms.attestations.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', AttestationStatus::Pending->value)
            ->assertJsonPath('data.0.learner.name', $learner->name)
            ->assertJsonPath('data.0.material.title', $lesson->title);

        $this->actingAs($examiner)
            ->getJson(route('lms.attestations.pending-count'))
            ->assertOk()
            ->assertJsonPath('data.pending', 1);
    }

    /** Чужая очередь — не то, о существовании чего стоит сообщать. */
    public function test_someone_elses_queue_is_empty_and_their_work_is_not_found(): void
    {
        [$lesson, $quiz] = $this->attestation();
        $this->submit($this->learner(), $lesson, $quiz);

        $stranger = $this->learner();

        $this->actingAs($stranger)
            ->getJson(route('lms.attestations.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($stranger)
            ->getJson(route('lms.attestations.show', QuizAttempt::query()->sole()))
            ->assertNotFound();
    }

    /** Проверяющий видит работу целиком, вместе с ключом: он по нему и сверяет. */
    public function test_the_examiner_opens_the_work_with_the_key(): void
    {
        [$lesson, $quiz, $examiner] = $this->attestation();
        $this->submit($this->learner(), $lesson, $quiz);

        $this->actingAs($examiner)
            ->getJson(route('lms.attestations.show', QuizAttempt::query()->sole()))
            ->assertOk()
            ->assertJsonPath('data.review.reveals_key', true)
            ->assertJsonPath('data.review.questions.0.is_answered', true);
    }

    /* ---------- Вердикт ---------- */

    public function test_a_pass_completes_the_lesson(): void
    {
        [$lesson, $quiz, $examiner] = $this->attestation();
        $learner = $this->learner();

        $this->submit($learner, $lesson, $quiz);

        $this->actingAs($examiner)
            ->postJson(route('lms.attestations.verdict', QuizAttempt::query()->sole()), [
                'is_accepted' => true,
                'comment' => 'Таблица заполнена верно.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', AttestationStatus::Passed->value)
            ->assertJsonPath('data.reviewed_by', $examiner->name);

        $this->assertTrue(QuizAttempt::query()->sole()->passed);
        $this->assertSame(1, LessonCompletion::query()->count(), 'Зачёт не закрыл урок.');
    }

    public function test_a_refusal_needs_a_reason(): void
    {
        [$lesson, $quiz, $examiner] = $this->attestation();
        $this->submit($this->learner(), $lesson, $quiz);

        $this->actingAs($examiner)
            ->postJson(route('lms.attestations.verdict', QuizAttempt::query()->sole()), [
                'is_accepted' => false,
                'comment' => '',
            ])
            ->assertJsonValidationErrorFor('comment');
    }

    /**
     * Отказ ничего не отменяет: попытка остаётся с объяснением, а сдать заново
     * можно — прежняя больше не держит очередь.
     */
    public function test_a_refusal_explains_itself_and_frees_the_way_for_a_retry(): void
    {
        [$lesson, $quiz, $examiner] = $this->attestation();
        $learner = $this->learner();

        $this->submit($learner, $lesson, $quiz);

        $this->actingAs($examiner)
            ->postJson(route('lms.attestations.verdict', QuizAttempt::query()->sole()), [
                'is_accepted' => false,
                'comment' => 'В таблице перепутаны месяцы.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', AttestationStatus::Failed->value);

        $this->assertSame(0, LessonCompletion::query()->count());

        // Сотрудник видит причину у себя на странице урока.
        $this->actingAs($learner)
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonPath('data.own_attempts.0.review_status', AttestationStatus::Failed->value)
            ->assertJsonPath('data.own_attempts.0.review_comment', 'В таблице перепутаны месяцы.')
            ->assertJsonPath('data.own_attempts.0.reviewed_by', $examiner->name);

        $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated();
    }

    /** Сотрудник видит, кому ушла работа: «ждёт проверки» без имени — загадка. */
    public function test_the_learner_sees_who_will_read_the_work(): void
    {
        [$lesson, , $examiner] = $this->attestation();

        $this->actingAs($this->learner())
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonPath('data.quiz.kind', QuizKind::Attestation->value)
            ->assertJsonPath('data.quiz.examiner.name', $examiner->name);
    }

    /** Дважды один и тот же вердикт не выносится. */
    public function test_a_reviewed_work_is_not_reviewed_again(): void
    {
        [$lesson, $quiz, $examiner] = $this->attestation();
        $this->submit($this->learner(), $lesson, $quiz);

        $attempt = QuizAttempt::query()->sole();

        $this->actingAs($examiner)
            ->postJson(route('lms.attestations.verdict', $attempt), ['is_accepted' => true])
            ->assertOk();

        $this->actingAs($examiner)
            ->postJson(route('lms.attestations.verdict', $attempt), ['is_accepted' => false, 'comment' => 'Передумал'])
            ->assertConflict();
    }

    /* ---------- helpers ---------- */

    private function lesson(): Lesson
    {
        return Course::factory()->withLessons(1)->create()->lessons()->firstOrFail();
    }

    /**
     * Урок с аттестацией из одного вопроса на выбор: таблицу через API не
     * ответить в одну строку, а проверяется здесь не она, а порядок.
     *
     * @return array{0: Lesson, 1: Quiz, 2: User}
     */
    private function attestation(): array
    {
        $lesson = $this->lesson();
        $examiner = $this->learner();

        $quiz = Quiz::factory()->withQuestions(1)->forLesson($lesson)->create([
            'kind' => QuizKind::Attestation,
            'examiner_id' => $examiner->getKey(),
        ]);

        return [$lesson, $quiz->load('questions.options'), $examiner];
    }

    private function submit(User $learner, Lesson $lesson, Quiz $quiz): void
    {
        $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated();
    }

    /**
     * @return array<int, list<int>>
     */
    private function correctAnswers(Quiz $quiz): array
    {
        return $quiz->questions()->with('options')->get()
            ->mapWithKeys(fn ($question): array => [
                $question->id => [$question->options->firstWhere('is_correct', true)->id],
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(QuizKind $kind, bool $table = false, ?User $examiner = null): array
    {
        $question = $table
            ? [
                'text' => 'Заполните план на квартал',
                'type' => QuestionType::Table->value,
                'points' => 1,
                'table' => [
                    'row_label_title' => 'Месяц',
                    'columns' => [['title' => 'План', 'kind' => 'text', 'options' => []]],
                    'rows' => [['label' => 'Январь']],
                    'can_add_rows' => false,
                ],
            ]
            : [
                'text' => 'Сколько будет два плюс два?',
                'type' => QuestionType::Single->value,
                'points' => 1,
                'options' => [
                    ['text' => 'Четыре', 'is_correct' => true],
                    ['text' => 'Пять', 'is_correct' => false],
                ],
            ];

        return [
            'title' => 'Проверка',
            'passing_score' => 100,
            'kind' => $kind->value,
            'examiner_id' => $examiner?->getKey(),
            'questions' => [$question],
        ];
    }
}
