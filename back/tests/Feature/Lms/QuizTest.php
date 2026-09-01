<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\QuestionType;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class QuizTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_the_answer_key_is_never_sent_to_a_learner(): void
    {
        [$course, $lesson] = $this->courseWithQuiz();
        $learner = $this->learner();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        $response = $this->actingAs($learner)
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk();

        $options = $response->json('data.quiz.questions.0.options');

        $this->assertNotEmpty($options);

        foreach ($options as $option) {
            $this->assertArrayNotHasKey('is_correct', $option);
        }
    }

    public function test_an_author_does_see_the_answer_key(): void
    {
        [, $lesson] = $this->courseWithQuiz();

        $this->actingAs($this->author())
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonPath('data.quiz.questions.0.options.0.is_correct', true);
    }

    public function test_a_correct_submission_passes_and_completes_the_lesson(): void
    {
        [$course, $lesson, $quiz] = $this->courseWithQuiz();
        $learner = $this->learner();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated()
            ->assertJsonPath('data.score', 100)
            ->assertJsonPath('data.passed', true);

        $this->assertSame(1, Enrollment::query()->sole()->completions()->count());
    }

    public function test_a_wrong_submission_fails_and_leaves_the_lesson_open(): void
    {
        [$course, $lesson, $quiz] = $this->courseWithQuiz();
        $learner = $this->learner();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->wrongAnswers($quiz)])
            ->assertCreated()
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.passed', false);

        $this->assertSame(0, Enrollment::query()->sole()->completions()->count());
    }

    public function test_a_half_correct_submission_scores_fifty(): void
    {
        [$course, $lesson, $quiz] = $this->courseWithQuiz(questions: 2);
        $learner = $this->learner();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        $answers = $this->correctAnswers($quiz);
        $questions = $quiz->questions()->with('options')->get();
        // Spoil the second answer only.
        $answers[$questions[1]->id] = [$questions[1]->options->firstWhere('is_correct', false)->id];

        $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $answers])
            ->assertCreated()
            ->assertJsonPath('data.score', 50)
            ->assertJsonPath('data.passed', false);
    }

    public function test_ticking_every_option_does_not_pass_a_single_choice_question(): void
    {
        [$course, $lesson, $quiz] = $this->courseWithQuiz(questions: 1);
        $learner = $this->learner();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        $question = $quiz->questions()->with('options')->first();
        $everyOption = $question->options->pluck('id')->all();

        $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => [$question->id => $everyOption]])
            ->assertCreated()
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.passed', false);
    }

    public function test_attempts_are_capped_when_a_limit_is_set(): void
    {
        [$course, $lesson, $quiz] = $this->courseWithQuiz(questions: 1);
        $quiz->update(['max_attempts' => 2]);

        $learner = $this->learner();
        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        $wrong = ['answers' => $this->wrongAnswers($quiz)];

        $this->actingAs($learner)->postJson(route('lms.quiz.submit', $lesson), $wrong)->assertCreated();
        $this->actingAs($learner)->postJson(route('lms.quiz.submit', $lesson), $wrong)->assertCreated();
        $this->actingAs($learner)->postJson(route('lms.quiz.submit', $lesson), $wrong)->assertConflict();
    }

    public function test_taking_a_quiz_enrols_the_reader_on_demand(): void
    {
        [, $lesson, $quiz] = $this->courseWithQuiz();

        $this->assertSame(0, Enrollment::query()->count());

        $this->actingAs($this->learner())
            ->postJson(route('lms.quiz.submit', $lesson), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated()
            ->assertJsonPath('data.passed', true);

        $this->assertSame(1, Enrollment::query()->count());
    }

    public function test_an_author_can_save_a_quiz(): void
    {
        $course = Course::factory()->withLessons(1)->create();
        $lesson = $course->lessons()->first();
        $author = $this->author();

        $payload = [
            'title' => 'Итоговый тест',
            'passing_score' => 80,
            'questions' => [[
                'text' => 'Сколько будет два плюс два?',
                'type' => QuestionType::Single->value,
                'points' => 1,
                'options' => [
                    ['text' => 'Четыре', 'is_correct' => true],
                    ['text' => 'Пять', 'is_correct' => false],
                ],
            ]],
        ];

        // Laravel answers 201 when the resource wraps a freshly created model.
        $this->actingAs($author)
            ->putJson(route('lms.quiz.save', $lesson), $payload)
            ->assertCreated()
            // Планку ставит правило, а не автор: тест при уроке зачитывается
            // при всех верных ответах, и присланные 80 не понижают её.
            ->assertJsonPath('data.passing_score', Quiz::PASSING_SCORE)
            ->assertJsonCount(1, 'data.questions');

        // Saving again replaces the quiz rather than stacking a second copy.
        $payload['questions'][] = [
            'text' => 'Второй вопрос',
            'type' => QuestionType::Single->value,
            'points' => 1,
            'options' => [
                ['text' => 'Да', 'is_correct' => true],
                ['text' => 'Нет', 'is_correct' => false],
            ],
        ];

        $this->actingAs($author)
            ->putJson(route('lms.quiz.save', $lesson), $payload)
            ->assertOk()
            ->assertJsonCount(2, 'data.questions');

        $this->assertSame(1, Quiz::query()->count());
    }

    public function test_a_question_without_a_correct_option_is_rejected(): void
    {
        $course = Course::factory()->withLessons(1)->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $course->lessons()->first()), [
                'title' => 'Тест',
                'passing_score' => 70,
                'questions' => [[
                    'text' => 'Вопрос',
                    'type' => QuestionType::Single->value,
                    'points' => 1,
                    'options' => [
                        ['text' => 'А', 'is_correct' => false],
                        ['text' => 'Б', 'is_correct' => false],
                    ],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions.0.options');
    }

    public function test_a_single_choice_question_with_two_correct_options_is_rejected(): void
    {
        $course = Course::factory()->withLessons(1)->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $course->lessons()->first()), [
                'title' => 'Тест',
                'passing_score' => 70,
                'questions' => [[
                    'text' => 'Вопрос',
                    'type' => QuestionType::Single->value,
                    'points' => 1,
                    'options' => [
                        ['text' => 'А', 'is_correct' => true],
                        ['text' => 'Б', 'is_correct' => true],
                    ],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions.0.options');
    }

    public function test_a_learner_cannot_edit_a_quiz(): void
    {
        $course = Course::factory()->withLessons(1)->create();

        $this->actingAs($this->learner())
            ->putJson(route('lms.quiz.save', $course->lessons()->first()), [
                'title' => 'Взлом',
                'passing_score' => 1,
                'questions' => [[
                    'text' => 'Вопрос',
                    'type' => QuestionType::Single->value,
                    'points' => 1,
                    'options' => [
                        ['text' => 'А', 'is_correct' => true],
                        ['text' => 'Б', 'is_correct' => false],
                    ],
                ]],
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: Course, 1: Lesson, 2: Quiz}
     */
    private function courseWithQuiz(int $questions = 2): array
    {
        $course = Course::factory()->withLessons(1)->create();
        $lesson = $course->lessons()->firstOrFail();
        $quiz = Quiz::factory()->withQuestions($questions)->forLesson($lesson)->create();

        return [$course, $lesson, $quiz->load('questions.options')];
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
