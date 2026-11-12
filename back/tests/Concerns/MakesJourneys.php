<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Actions\Onboarding\ApplyTemplate;
use App\Actions\Onboarding\OpenJourney;
use App\Enums\Permission;
use App\Models\OnboardingJourney;
use App\Models\OnboardingJourneyStage;
use App\Models\OnboardingStage;
use App\Models\OnboardingTemplate;
use App\Models\Quiz;
use App\Models\User;

/**
 * Личные маршруты для тестов: шаблон-трафарет и наполненная карта.
 */
trait MakesJourneys
{
    /** Ведёт адаптацию — и ничего больше. */
    protected function hr(): User
    {
        return $this->userWith(Permission::ManageOnboarding);
    }

    protected function employee(?User $mentor = null): User
    {
        $employee = User::factory()->create();

        if ($mentor !== null) {
            $employee->forceFill(['mentor_id' => $mentor->getKey()])->save();
        }

        return $employee;
    }

    /** Старое имя — тот же человек: маршрут теперь у всякого, не только у новичка. */
    protected function trainee(?User $mentor = null): User
    {
        return $this->employee($mentor);
    }

    /**
     * Маршрут сотрудника, наполненный из шаблона с N этапами по дню на этап.
     *
     * Содержимое шаблона задаётся колбэком ДО наложения: с трафарета снимают
     * копию, и добавленное после до уже наложенного не доедет — как в жизни.
     *
     * @param  callable(OnboardingTemplate): void|null  $prepare
     * @return array{0: OnboardingJourney, 1: OnboardingTemplate}
     */
    protected function journeyFor(
        User $employee,
        int $stages = 3,
        array $templateState = [],
        ?callable $prepare = null,
        string $anchor = 'today',
    ): array {
        $template = OnboardingTemplate::factory()->withStages($stages)->create($templateState);

        if ($prepare !== null) {
            $prepare($template);
        }

        $journey = app(OpenJourney::class)->handle($employee);

        app(ApplyTemplate::class)->handle($journey, $template, ['anchor' => $anchor], $this->hr());

        return [$journey->refresh()->load('stages'), $template];
    }

    /** Пустой маршрут: карта заведена, в ней ничего нет. */
    protected function emptyJourneyFor(User $employee): OnboardingJourney
    {
        return app(OpenJourney::class)->handle($employee);
    }

    /** Тест при этапе шаблона: один вопрос, первый вариант верный. */
    protected function quizFor(OnboardingStage $stage, int $maxAttempts = 2, int $passingScore = 100): Quiz
    {
        return Quiz::factory()
            ->withQuestions(1)
            ->create([
                'quizzable_type' => $stage->getMorphClass(),
                'quizzable_id' => $stage->getKey(),
                'max_attempts' => $maxAttempts,
                'passing_score' => $passingScore,
            ])
            ->load('questions.options');
    }

    /** Тест, доставшийся этапу маршрута копией с трафарета. */
    protected function journeyQuizOf(OnboardingJourneyStage $stage): Quiz
    {
        return $stage->loadMissing('quiz.questions.options')->quiz;
    }

    /**
     * @return array<int, list<int>>
     */
    protected function answersFor(Quiz $quiz, bool $correct = true): array
    {
        $answers = [];

        foreach ($quiz->questions as $question) {
            $option = $question->options->firstWhere('is_correct', $correct)
                ?? $question->options->first();

            $answers[$question->getKey()] = [$option->getKey()];
        }

        return $answers;
    }
}
