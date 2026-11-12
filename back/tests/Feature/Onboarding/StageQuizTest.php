<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\OnboardingJourney;
use App\Models\OnboardingTemplate;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class StageQuizTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_a_passed_quiz_completes_the_stage(): void
    {
        [$trainee, $journey, $quiz] = $this->journeyWithQuiz();

        $stage = $journey->stages->first();

        $this->actingAs($trainee)
            ->postJson(route('onboarding.quiz.submit', $stage), ['answers' => $this->answersFor($quiz)])
            ->assertCreated()
            ->assertJsonPath('data.passed', true);

        $this->assertNotNull($stage->refresh()->completed_at);
    }

    public function test_the_answer_key_is_hidden_from_the_trainee(): void
    {
        [$trainee, $journey] = $this->journeyWithQuiz();

        $response = $this->actingAs($trainee)
            ->getJson(route('onboarding.quiz.show', $journey->stages->first()))
            ->assertOk();

        foreach ($response->json('data.questions.0.options') as $option) {
            $this->assertArrayNotHasKey('is_correct', $option);
        }
    }

    public function test_a_locked_stage_keeps_its_quiz_to_itself(): void
    {
        [$trainee, $journey] = $this->journeyWithQuiz(quizDay: 2);

        $locked = $journey->stages->get(1);

        $this->actingAs($trainee)
            ->getJson(route('onboarding.quiz.show', $locked))
            ->assertForbidden();

        $this->actingAs($trainee)
            ->postJson(route('onboarding.quiz.submit', $locked), ['answers' => []])
            ->assertConflict();
    }

    public function test_two_attempts_then_failed_then_a_granted_one_saves_the_day(): void
    {
        [$trainee, $journey, $quiz] = $this->journeyWithQuiz();

        $stage = $journey->stages->first();
        $wrong = ['answers' => $this->answersFor($quiz, correct: false)];

        $this->actingAs($trainee)->postJson(route('onboarding.quiz.submit', $stage), $wrong)->assertCreated();
        $this->actingAs($trainee)->postJson(route('onboarding.quiz.submit', $stage), $wrong)->assertCreated();

        // Третья попытка не принимается, статус этапа — «тест не сдан».
        $this->actingAs($trainee)
            ->postJson(route('onboarding.quiz.submit', $stage), $wrong)
            ->assertConflict();

        $this->actingAs($trainee)
            ->getJson(route('onboarding.my'))
            ->assertJsonPath('data.stages.0.status', 'quiz_failed')
            ->assertJsonPath('data.next_action.kind', 'quiz_failed');

        // Добавленная попытка открывает дорогу — и этап закрывается сдачей.
        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.grant-attempt', [$trainee, $stage]))
            ->assertOk();

        $this->actingAs($trainee)
            ->postJson(route('onboarding.quiz.submit', $stage), ['answers' => $this->answersFor($quiz)])
            ->assertCreated()
            ->assertJsonPath('data.passed', true);

        $this->assertNotNull($stage->refresh()->completed_at);
    }

    public function test_attempt_history_sits_on_the_quiz_card(): void
    {
        [$trainee, $journey, $quiz] = $this->journeyWithQuiz();

        $stage = $journey->stages->first();

        $this->actingAs($trainee)
            ->postJson(route('onboarding.quiz.submit', $stage), ['answers' => $this->answersFor($quiz, correct: false)]);

        $this->actingAs($trainee)
            ->getJson(route('onboarding.my'))
            ->assertJsonPath('data.stages.0.items.0.meta.attempts_spent', 1)
            ->assertJsonPath('data.stages.0.items.0.meta.attempts_allowed', 2);
    }

    public function test_the_best_score_of_predecessors_comes_nameless(): void
    {
        [$trainee, $journey] = $this->journeyWithQuiz();

        /*
         * Рекорд считается по копиям одного трафарета: у прошлого участника
         * свой маршрут и свой тест, а сравнивают их по общему источнику.
         */
        $predecessor = $this->employee();
        $theirs = $this->applySameTemplate($predecessor, $journey);

        $this->journeyQuizOf($theirs->stages->first())->attempts()->create([
            'user_id' => $predecessor->getKey(),
            'score' => 100,
            'passed' => true,
            'answers' => [],
            'scores' => [],
            'completed_at' => now(),
            'review_status' => 'auto',
        ]);

        $response = $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();

        // Цифра есть, имени рядом нет — и не должно быть.
        $this->assertSame(100, $response->json('data.stages.0.items.0.meta.best_score_others'));
        $this->assertStringNotContainsString(
            $predecessor->name,
            json_encode($response->json('data.stages.0'), JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Тот же трафарет — другому человеку: так появляется «прошлый участник»,
     * с которым сравнивается рекорд.
     */
    private function applySameTemplate(User $employee, OnboardingJourney $journey): OnboardingJourney
    {
        $template = $journey->stages->first()->sourceStage->template;

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.templates.apply', [$employee, $template]), ['anchor' => 'today'])
            ->assertOk();

        return OnboardingJourney::query()->where('user_id', $employee->getKey())->sole()->load('stages');
    }

    /**
     * @return array{0: User, 1: OnboardingJourney, 2: Quiz}
     */
    private function journeyWithQuiz(int $quizDay = 1): array
    {
        $trainee = $this->trainee();

        [$journey] = $this->journeyFor(
            $trainee,
            stages: max(1, $quizDay),
            prepare: function (OnboardingTemplate $template) use ($quizDay): void {
                $this->quizFor($template->stages()->get()[$quizDay - 1], maxAttempts: 2, passingScore: 100);
            },
        );

        // Тест маршрута — копия трафарета со своими номерами вопросов: отвечать
        // надо ей, иначе ответы не сойдутся ни с одним вопросом.
        $quiz = $this->journeyQuizOf($journey->stages->get($quizDay - 1));

        return [$trainee, $journey, $quiz];
    }
}
