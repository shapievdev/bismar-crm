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

    /**
     * Правка теста не стирает того, что люди уже отвечали.
     *
     * Попытка хранит ответы, разложенные по номерам вопросов и выбранных
     * вариантов. Пока редактор присылает номера обратно, вопрос остаётся собой:
     * сотрудник и через месяц видит в разборе, что именно он выбрал, а
     * статистика вопроса не считает его нетронутым.
     */
    public function test_editing_a_quiz_keeps_the_answers_of_past_attempts(): void
    {
        $course = Course::factory()->withLessons(1)->create();
        $lesson = $course->lessons()->firstOrFail();
        $author = $this->author();

        $payload = [
            'title' => 'Итоговый тест',
            'passing_score' => 100,
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

        $saved = $this->actingAs($author)
            ->putJson(route('lms.quiz.save', $lesson), $payload)
            ->assertCreated()
            ->json('data.questions.0');

        $right = collect($saved['options'])->firstWhere('is_correct', true);

        $learner = $this->learner();

        $attempt = $this->actingAs($learner)
            ->postJson(route('lms.quiz.submit', $lesson), [
                'answers' => [$saved['id'] => [$right['id']]],
            ])
            ->assertCreated()
            ->json('data.id');

        // Автор поправил формулировку и дописал второй вопрос — с номерами
        // того, что уже было, как их присылает редактор.
        $payload['questions'][0]['id'] = $saved['id'];
        $payload['questions'][0]['text'] = 'Сколько будет 2 + 2?';
        $payload['questions'][0]['options'] = array_map(
            static fn (array $option, int $index): array => $option + ['id' => $saved['options'][$index]['id']],
            $payload['questions'][0]['options'],
            array_keys($payload['questions'][0]['options']),
        );
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
            ->assertJsonCount(2, 'data.questions')
            // Правленый вопрос — тот же самый, а не его двойник с новым номером.
            ->assertJsonPath('data.questions.0.id', $saved['id'])
            ->assertJsonPath('data.questions.0.options.0.id', $right['id']);

        $this->actingAs($learner)
            ->getJson(route('lms.attempts.show', $attempt))
            ->assertOk()
            ->assertJsonPath('data.review.questions.0.is_answered', true)
            ->assertJsonPath('data.review.questions.0.is_correct', true)
            ->assertJsonPath('data.review.questions.0.selected_option_ids', [$right['id']])
            // Дописанный после попытки вопрос числится неотвеченным: его и не
            // показывали.
            ->assertJsonPath('data.review.questions.1.is_answered', false);

        $this->actingAs($author)
            ->getJson(route('lms.quiz.statistics', $lesson))
            ->assertOk()
            ->assertJsonPath('data.questions.0.answered', 1)
            ->assertJsonPath('data.questions.0.correct', 1);
    }

    /** Снятый вопрос уходит совсем: иначе он жил бы в тесте вечно. */
    public function test_a_question_dropped_from_the_editor_is_removed(): void
    {
        $course = Course::factory()->withLessons(1)->create();
        $lesson = $course->lessons()->firstOrFail();
        $quiz = Quiz::factory()->withQuestions(2)->forLesson($lesson)->create();

        $kept = $quiz->questions()->with('options')->orderBy('position')->firstOrFail();

        $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $lesson), [
                'title' => $quiz->title,
                'passing_score' => 100,
                'questions' => [[
                    'id' => $kept->getKey(),
                    'text' => $kept->text,
                    'type' => $kept->type->value,
                    'points' => $kept->points,
                    'options' => $kept->options->map(fn ($option): array => [
                        'id' => $option->id,
                        'text' => $option->text,
                        'is_correct' => (bool) $option->is_correct,
                    ])->all(),
                ]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonPath('data.questions.0.id', $kept->getKey());

        $this->assertSame(1, $quiz->questions()->count());
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
