<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\CourseVisibility;
use App\Enums\Permission;
use App\Enums\QuestionType;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Проверка при документе.
 *
 * Есть проверка — значит ознакомление засчитывается сдачей, а не нажатием
 * кнопки: сначала прочитал, потом ответил верно на всё, и только тогда числится
 * ознакомленным (решение пользователя 2026-09-01).
 */
final class DocumentQuizTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Тот, кто ведёт документы. */
    private function author(): User
    {
        return $this->userWith(Permission::ViewCourses, Permission::UpdateCourses);
    }

    /**
     * @return array{Regulation, Quiz}
     */
    private function documentWithQuiz(int $questions = 2): array
    {
        $document = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);
        $quiz = Quiz::factory()->withQuestions($questions)->forRegulation($document)->create();

        return [$document, $quiz];
    }

    /**
     * @return array<int, list<int>>
     */
    private function answers(Quiz $quiz, bool $correct = true): array
    {
        return $quiz->questions()->with('options')->get()
            ->mapWithKeys(fn ($question): array => [
                $question->id => [$question->options->firstWhere('is_correct', $correct)->id],
            ])->all();
    }

    /* ---------- Кто её заводит ---------- */

    public function test_an_author_attaches_a_quiz_to_a_document(): void
    {
        $document = Regulation::factory()->published()->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.regulations.quiz.save', $document), [
                'title' => 'Проверка знаний',
                'passing_score' => 70,
                'questions' => [[
                    'text' => 'Что делать с возвратом?',
                    'type' => QuestionType::Single->value,
                    'points' => 1,
                    'options' => [
                        ['text' => 'Оформить документом', 'is_correct' => true],
                        ['text' => 'Отдать из кассы', 'is_correct' => false],
                    ],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Проверка знаний')
            // Планку ставит правило, а не автор: документ зачитывается, когда
            // все ответы верны.
            ->assertJsonPath('data.passing_score', Quiz::PASSING_SCORE)
            ->assertJsonCount(1, 'data.questions');

        $this->assertSame(1, $document->quiz()->count());
    }

    public function test_a_reader_cannot_attach_a_quiz(): void
    {
        $document = Regulation::factory()->published()->create();

        $this->actingAs($this->learner())
            ->putJson(route('lms.regulations.quiz.save', $document), [
                'title' => 'Проверка',
                'passing_score' => 70,
                'questions' => [[
                    'text' => 'Вопрос',
                    'type' => QuestionType::Single->value,
                    'points' => 1,
                    'options' => [['text' => 'Да', 'is_correct' => true], ['text' => 'Нет', 'is_correct' => false]],
                ]],
            ])
            ->assertForbidden();
    }

    /**
     * Снятая проверка не отменяет уже засчитанных ознакомлений: человек её
     * действительно прошёл.
     */
    public function test_removing_the_quiz_keeps_what_was_already_earned(): void
    {
        [$document, $quiz] = $this->documentWithQuiz(1);
        $reader = $this->learner();

        $this->actingAs($reader)
            ->postJson(route('lms.regulations.quiz.submit', $document), ['answers' => $this->answers($quiz)])
            ->assertCreated();

        $this->actingAs($this->author())
            ->deleteJson(route('lms.regulations.quiz.destroy', $document))
            ->assertNoContent();

        $this->assertSame(0, $document->quiz()->count());
        $this->assertTrue($document->refresh()->isAcknowledgedBy($reader));
    }

    /* ---------- Ознакомление засчитывается сдачей ---------- */

    public function test_passing_the_quiz_counts_as_reading_the_document(): void
    {
        [$document, $quiz] = $this->documentWithQuiz();
        $reader = $this->learner();

        $this->actingAs($reader)
            ->postJson(route('lms.regulations.quiz.submit', $document), ['answers' => $this->answers($quiz)])
            ->assertCreated()
            ->assertJsonPath('data.score', 100)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.is_acknowledged', true)
            // Разбор приходит сразу: где ошибся, человек хочет знать в ту же
            // секунду, когда увидел результат. А верные ответы — нет: сдача
            // ключа не открывает, иначе первый же сдавший пересказал бы его
            // остальным.
            ->assertJsonCount(2, 'data.review.questions')
            ->assertJsonPath('data.review.reveals_key', false)
            ->assertJsonPath('data.review.questions.0.options.0.is_correct', null);

        $this->assertTrue($document->isAcknowledgedBy($reader));
    }

    /** С ошибкой документ не зачитывается: планка — все верные ответы. */
    public function test_one_wrong_answer_leaves_the_document_unread(): void
    {
        [$document, $quiz] = $this->documentWithQuiz();
        $questions = $quiz->questions()->with('options')->get();
        $reader = $this->learner();

        $half = [
            $questions[0]->id => [$questions[0]->options->firstWhere('is_correct', true)->id],
            $questions[1]->id => [$questions[1]->options->firstWhere('is_correct', false)->id],
        ];

        $this->actingAs($reader)
            ->postJson(route('lms.regulations.quiz.submit', $document), ['answers' => $half])
            ->assertCreated()
            ->assertJsonPath('data.score', 50)
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.is_acknowledged', false);

        $this->assertFalse($document->isAcknowledgedBy($reader));
    }

    /**
     * При проверке кнопки «ознакомлен» нет вовсе: нажатие обесценивало бы тест.
     */
    public function test_the_button_is_closed_while_a_quiz_is_attached(): void
    {
        [$document] = $this->documentWithQuiz(1);

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.acknowledge', $document))
            ->assertStatus(409);

        $this->assertSame(0, $document->acknowledgements()->count());
    }

    public function test_a_document_without_a_quiz_is_still_marked_by_hand(): void
    {
        $document = Regulation::factory()->published()->create();

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.acknowledge', $document))
            ->assertOk()
            ->assertJsonPath('data.is_acknowledged', true);
    }

    /* ---------- Что видно на экране ---------- */

    public function test_the_document_carries_its_quiz_and_the_readers_own_attempts(): void
    {
        [$document, $quiz] = $this->documentWithQuiz(1);
        $reader = $this->learner();

        $this->actingAs($reader)
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => $this->answers($quiz, correct: false),
            ])
            ->assertCreated();

        $this->actingAs($reader)
            ->getJson(route('lms.regulations.show', $document))
            ->assertOk()
            ->assertJsonPath('data.quiz.title', $quiz->title)
            ->assertJsonCount(1, 'data.quiz.questions')
            ->assertJsonCount(1, 'data.own_attempts')
            ->assertJsonPath('data.own_attempts.0.passed', false)
            ->assertJsonPath('data.is_acknowledged', false);
    }

    /** Ключ читателю не отдают ни в уроке, ни в документе. */
    public function test_the_answer_key_is_never_sent_to_a_reader(): void
    {
        [$document] = $this->documentWithQuiz(1);

        $response = $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.show', $document))
            ->assertOk();

        $this->assertArrayNotHasKey('is_correct', $response->json('data.quiz.questions.0.options.0'));
    }

    /* ---------- Разбор прошлой попытки ---------- */

    /**
     * Разбор своей попытки открывается и потом: закрытый документ при этом
     * остаётся закрытым — доступ к попытке решает сам документ.
     */
    public function test_a_reader_reopens_the_review_of_a_past_attempt(): void
    {
        [$document, $quiz] = $this->documentWithQuiz(1);
        $reader = $this->learner();

        $this->actingAs($reader)
            ->postJson(route('lms.regulations.quiz.submit', $document), ['answers' => $this->answers($quiz)])
            ->assertCreated();

        $attempt = QuizAttempt::query()->sole();

        $this->actingAs($reader)
            ->getJson(route('lms.attempts.show', $attempt))
            ->assertOk()
            ->assertJsonPath('data.id', $attempt->getKey())
            ->assertJsonCount(1, 'data.review.questions');
    }

    /**
     * Ведущему документ видно, кто проверку проходил, и что каждый отправил:
     * доля по вопросу говорит о тексте документа, а попытка — о человеке.
     */
    public function test_the_author_reads_who_took_the_check_and_what_they_sent(): void
    {
        [$document, $quiz] = $this->documentWithQuiz(1);
        $reader = $this->learner();

        $this->actingAs($reader)
            ->postJson(
                route('lms.regulations.quiz.submit', $document),
                ['answers' => $this->answers($quiz, correct: false)],
            )
            ->assertCreated();

        $attempt = QuizAttempt::query()->sole();

        $this->actingAs($this->author())
            ->getJson(route('lms.regulations.quiz.statistics', $document))
            ->assertOk()
            ->assertJsonPath('data.people.0.id', $reader->getKey())
            ->assertJsonPath('data.people.0.passed', false)
            ->assertJsonPath('data.people.0.attempts.0.id', $attempt->getKey());

        $this->actingAs($this->author())
            ->getJson(route('lms.regulations.quiz.attempt', [$document, $attempt]))
            ->assertOk()
            ->assertJsonPath('data.review.reveals_key', true)
            ->assertJsonPath('data.review.questions.0.is_correct', false);
    }

    /** Чужие ответы открывает право вести документ, а читателю его не давали. */
    public function test_a_reader_may_not_read_someone_elses_attempt(): void
    {
        [$document, $quiz] = $this->documentWithQuiz(1);

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), ['answers' => $this->answers($quiz)])
            ->assertCreated();

        $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.quiz.attempt', [$document, QuizAttempt::query()->sole()]))
            ->assertForbidden();
    }

    public function test_a_review_is_out_of_reach_when_the_document_is_closed(): void
    {
        [$document, $quiz] = $this->documentWithQuiz(1);
        $reader = $this->learner();

        $this->actingAs($reader)
            ->postJson(route('lms.regulations.quiz.submit', $document), ['answers' => $this->answers($quiz)])
            ->assertCreated();

        // Документ закрыли уже после сдачи: чужой закрытый документ для этого
        // человека не существует — вместе с его частями.
        $document->update(['visibility' => CourseVisibility::Private]);

        $this->actingAs($reader)
            ->getJson(route('lms.attempts.show', QuizAttempt::query()->sole()))
            ->assertNotFound();
    }
}
