<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Разбор теста — ученику и автору.
 *
 * Балл без разбора почти ничему не учит: сотрудник видит «50%» и идёт
 * пересдавать с тем же знанием, с каким пришёл. Автор же по разбору узнаёт то,
 * чего иначе не узнает вовсе, — какой вопрос заваливают все, а значит, о чём в
 * уроке сказано плохо.
 */
final class QuizReviewTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * Пока попытки остались, ключ не выдаётся.
     *
     * Иначе тест с неограниченным числом попыток проходится так: отправить
     * что угодно, посмотреть верные ответы, отправить их обратно.
     */
    public function test_a_failed_attempt_shows_the_mistakes_but_not_the_key(): void
    {
        [$lesson, $quiz] = $this->lessonWithQuiz(questions: 2);
        $learner = $this->learner();

        $response = $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->wrongAnswers($quiz)])
            ->assertCreated()
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.review.reveals_key', false)
            ->assertJsonPath('data.review.questions.0.is_correct', false)
            ->assertJsonPath('data.review.questions.0.is_answered', true);

        // Что выбрал сам — видно: без этого разбор не разбор.
        $chosen = $response->json('data.review.questions.0.selected_option_ids');
        $this->assertCount(1, $chosen);

        foreach ($response->json('data.review.questions.0.options') as $option) {
            $this->assertNull($option['is_correct'], 'Верный ответ выдан до сдачи.');
        }
    }

    /**
     * Сдача ключа не открывает: сдавшему он и не нужен — планка равна всем
     * верным ответам, то есть ключ и есть то, что он сам отправил, — а
     * вывешенный после сдачи он превращается в готовые ответы для остальных
     * (решение пользователя 2026-09-02).
     */
    public function test_passing_does_not_reveal_the_key(): void
    {
        [$lesson, $quiz] = $this->lessonWithQuiz(questions: 2);

        $response = $this->actingAs($this->learner())
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.review.reveals_key', false)
            // Свой ответ виден: человек знает, что выбрал, — новостью это не
            // будет. Неизвестным остаётся только «а верен ли он».
            ->assertJsonPath('data.review.questions.0.is_correct', true);

        $options = $response->json('data.review.questions.0.options');

        $this->assertNull($options[0]['is_correct']);
        $this->assertTrue($options[0]['is_chosen']);
        $this->assertNull($options[1]['is_correct']);
    }

    /**
     * Попытки кончились — скрывать больше нечего: пересдать всё равно нельзя,
     * а разобраться в ошибках человек по-прежнему вправе.
     */
    public function test_the_last_attempt_reveals_the_key_even_when_failed(): void
    {
        [$lesson, $quiz] = $this->lessonWithQuiz(questions: 1);
        $quiz->update(['max_attempts' => 1]);

        $this->actingAs($this->learner())
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->wrongAnswers($quiz)])
            ->assertCreated()
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.review.reveals_key', true)
            ->assertJsonPath('data.review.questions.0.options.0.is_correct', true);
    }

    /** Вопрос, добавленный после попытки, числится неотвеченным: его и не показывали. */
    public function test_a_question_added_after_the_attempt_counts_as_unanswered(): void
    {
        [$lesson, $quiz] = $this->lessonWithQuiz(questions: 1);
        $learner = $this->learner();

        $attempt = $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated()
            ->json('data.id');

        $added = $quiz->questions()->create(['text' => 'Новый вопрос', 'points' => 1, 'position' => 1]);
        $added->options()->create(['text' => 'Верно', 'is_correct' => true, 'position' => 0]);

        $this->actingAs($learner)
            ->getJson(route('lms.attempts.show', $attempt))
            ->assertOk()
            ->assertJsonCount(2, 'data.review.questions')
            ->assertJsonPath('data.review.questions.1.is_answered', false)
            ->assertJsonPath('data.review.questions.1.is_correct', false)
            ->assertJsonPath('data.review.questions.1.selected_option_ids', []);
    }

    public function test_a_learner_reopens_the_review_of_a_past_attempt(): void
    {
        [$lesson, $quiz] = $this->lessonWithQuiz(questions: 1);
        $learner = $this->learner();

        $attempt = $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($learner)
            ->getJson(route('lms.attempts.show', $attempt))
            ->assertOk()
            ->assertJsonPath('data.id', $attempt)
            ->assertJsonPath('data.review.questions.0.is_correct', true);
    }

    /** Чужая попытка — не то, о существовании чего стоит сообщать. */
    public function test_another_learners_attempt_is_not_found(): void
    {
        [$lesson, $quiz] = $this->lessonWithQuiz(questions: 1);

        $attempt = $this->actingAs($this->learner())
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->learner())
            ->getJson(route('lms.attempts.show', $attempt))
            ->assertNotFound();
    }

    /* ---------- Автору ---------- */

    public function test_the_statistics_show_what_is_chosen_instead_of_the_right_answer(): void
    {
        [$lesson, $quiz] = $this->lessonWithQuiz(questions: 1);

        $question = $quiz->questions()->with('options')->firstOrFail();
        $right = $question->options->firstWhere('is_correct', true);
        $wrong = $question->options->firstWhere('is_correct', false);

        $this->answerAs($this->learner(), $lesson, [$question->id => [$right->id]]);
        $this->answerAs($this->learner(), $lesson, [$question->id => [$wrong->id]]);
        $this->answerAs($this->learner(), $lesson, [$question->id => [$wrong->id]]);

        $response = $this->actingAs($this->author())
            ->getJson(route('lms.quiz.statistics', $lesson))
            ->assertOk()
            ->assertJsonPath('data.attempts', 3)
            ->assertJsonPath('data.learners', 3)
            ->assertJsonPath('data.passed', 1)
            ->assertJsonPath('data.questions.0.answered', 3)
            ->assertJsonPath('data.questions.0.correct', 1)
            ->assertJsonPath('data.questions.0.correct_share', 33);

        $options = collect($response->json('data.questions.0.options'))->keyBy('id');

        $this->assertSame(1, $options[$right->id]['chosen']);
        $this->assertSame(2, $options[$wrong->id]['chosen'], 'Неверный вариант не посчитан.');
    }

    /**
     * Вторая попытка испорчена тем, что человек уже видел разбор: по ней
     * вопрос выглядит лёгким, как бы плохо он ни был написан.
     */
    public function test_the_statistics_count_the_first_attempt_of_each_learner(): void
    {
        [$lesson, $quiz] = $this->lessonWithQuiz(questions: 1);
        $learner = $this->learner();

        $this->answerAs($learner, $lesson, $this->wrongAnswers($quiz));
        $this->answerAs($learner, $lesson, $this->correctAnswers($quiz));

        $this->actingAs($this->author())
            ->getJson(route('lms.quiz.statistics', $lesson))
            ->assertOk()
            ->assertJsonPath('data.attempts', 2)
            // Человек один, сдал — но вопрос с первого раза не дался.
            ->assertJsonPath('data.learners', 1)
            ->assertJsonPath('data.passed', 1)
            ->assertJsonPath('data.questions.0.correct', 0)
            ->assertJsonPath('data.questions.0.correct_share', 0)
            ->assertJsonPath('data.average_first_score', 0);
    }

    public function test_a_learner_may_not_read_the_statistics(): void
    {
        [$lesson] = $this->lessonWithQuiz(questions: 1);

        $this->actingAs($this->learner())
            ->getJson(route('lms.quiz.statistics', $lesson))
            ->assertForbidden();
    }

    /**
     * За долями по вопросам стоят люди, и найти их можно только поимённо:
     * «сдали двое из трёх» не говорит, с кем садиться и разбирать материал.
     */
    public function test_the_statistics_name_who_took_the_test(): void
    {
        [$lesson, $quiz] = $this->lessonWithQuiz(questions: 1);

        $failed = tap($this->learner())->update(['last_name' => 'Яковлев']);
        $passed = tap($this->learner())->update(['last_name' => 'Абрамов']);

        $this->answerAs($failed, $lesson, $this->wrongAnswers($quiz));
        $this->answerAs($passed, $lesson, $this->correctAnswers($quiz));

        $people = $this->actingAs($this->author())
            ->getJson(route('lms.quiz.statistics', $lesson))
            ->assertOk()
            ->json('data.people');

        // Не сдавшие идут первыми — ради них список и читают, а фамилия здесь
        // как раз спорит с порядком.
        $this->assertSame($failed->getKey(), $people[0]['id']);
        $this->assertFalse($people[0]['passed']);
        $this->assertCount(1, $people[0]['attempts']);

        $this->assertSame($passed->getKey(), $people[1]['id']);
        $this->assertTrue($people[1]['passed']);
        $this->assertSame(100, $people[1]['best_score']);
    }

    /** Все попытки человека, а не первая: с чем он пришёл во второй раз — тоже разговор. */
    public function test_the_statistics_list_every_attempt_of_a_person(): void
    {
        [$lesson, $quiz] = $this->lessonWithQuiz(questions: 1);
        $learner = $this->learner();

        $this->answerAs($learner, $lesson, $this->wrongAnswers($quiz));
        $this->answerAs($learner, $lesson, $this->correctAnswers($quiz));

        $people = $this->actingAs($this->author())
            ->getJson(route('lms.quiz.statistics', $lesson))
            ->assertOk()
            ->json('data.people');

        $this->assertCount(1, $people);
        $this->assertCount(2, $people[0]['attempts']);
        $this->assertFalse($people[0]['attempts'][0]['passed'], 'Попытки идут не по порядку.');
        $this->assertTrue($people[0]['attempts'][1]['passed']);
    }

    /**
     * Автору верные ответы открыты и в чужой попытке: он их сам и написал, а
     * без них не прочесть, чем сотрудник заменил верный вариант.
     */
    public function test_the_author_reads_the_review_of_a_learners_attempt(): void
    {
        [$lesson, $quiz] = $this->lessonWithQuiz(questions: 1);
        $learner = $this->learner();

        $attempt = $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->wrongAnswers($quiz)])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->author())
            ->getJson(route('lms.quiz.attempt', [$lesson, $attempt]))
            ->assertOk()
            ->assertJsonPath('data.id', $attempt)
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.review.reveals_key', true)
            ->assertJsonPath('data.review.questions.0.is_correct', false)
            ->assertJsonPath('data.review.questions.0.options.0.is_correct', true);
    }

    /** Право смотреть чужие ответы даёт материал, а сотруднику его не давали. */
    public function test_a_learner_may_not_read_someone_elses_attempt(): void
    {
        [$lesson, $quiz] = $this->lessonWithQuiz(questions: 1);

        $attempt = $this->actingAs($this->learner())
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->learner())
            ->getJson(route('lms.quiz.attempt', [$lesson, $attempt]))
            ->assertForbidden();
    }

    /** Правом на свой урок чужой не открыть: попытка не от этого теста — 404. */
    public function test_an_attempt_of_another_quiz_is_not_found(): void
    {
        [$lesson, $quiz] = $this->lessonWithQuiz(questions: 1);
        [$other] = $this->lessonWithQuiz(questions: 1);

        $attempt = $this->actingAs($this->learner())
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->author())
            ->getJson(route('lms.quiz.attempt', [$other, $attempt]))
            ->assertNotFound();
    }

    public function test_the_statistics_of_a_lesson_without_a_quiz_are_not_found(): void
    {
        $course = Course::factory()->withLessons(1)->create();

        $this->actingAs($this->author())
            ->getJson(route('lms.quiz.statistics', $course->lessons()->firstOrFail()))
            ->assertNotFound();
    }

    /* ---------- helpers ---------- */

    /**
     * @return array{0: Lesson, 1: Quiz}
     */
    private function lessonWithQuiz(int $questions = 2): array
    {
        $course = Course::factory()->withLessons(1)->create();
        $lesson = $course->lessons()->firstOrFail();
        $quiz = Quiz::factory()->withQuestions($questions)->forLesson($lesson)->create();

        return [$lesson, $quiz->load('questions.options')];
    }

    /**
     * @param  array<int, list<int>>  $answers
     */
    private function answerAs(User $learner, Lesson $lesson, array $answers): void
    {
        $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $answers])
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
     * @return array<int, list<int>>
     */
    private function wrongAnswers(Quiz $quiz): array
    {
        return $quiz->questions()->with('options')->get()
            ->mapWithKeys(fn ($question): array => [
                $question->id => [$question->options->firstWhere('is_correct', false)->id],
            ])->all();
    }
}
