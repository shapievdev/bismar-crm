<?php

declare(strict_types=1);

namespace Tests\Feature\Achievements;

use App\Models\Achievement;
use App\Models\AchievementAward;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\OnboardingJourney;
use App\Models\OnboardingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Значки за закрытые шаги маршрута — то, ради чего достижения и заведены.
 */
final class AchievementAwardTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_a_closed_stage_hands_out_its_badge(): void
    {
        $badge = Achievement::query()->create(['title' => 'Первый день позади']);
        $trainee = $this->trainee();

        // Этап без требований закрывается в день открытия — значок приходит
        // вместе с ним.
        $this->journeyFor($trainee, stages: 1, prepare: function (OnboardingTemplate $template) use ($badge): void {
            $template->stages()->first()->update(['achievement_id' => $badge->getKey()]);
        });

        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();

        $this->actingAs($trainee)
            ->getJson(route('profile.achievements'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Первый день позади')
            ->assertJsonPath('data.0.reason', 'Этап «Этап 1»');
    }

    public function test_a_badge_for_a_single_material_arrives_before_the_stage_is_over(): void
    {
        $badge = Achievement::query()->create(['title' => 'Знаток кассы']);
        $trainee = $this->trainee();
        $course = Course::factory()->create();

        $this->journeyFor($trainee, stages: 1, prepare: function (OnboardingTemplate $template) use ($course, $badge): void {
            $stage = $template->stages()->first();

            $stage->links()->create([
                'linkable_type' => 'course',
                'linkable_id' => $course->getKey(),
                'position' => 1,
                'is_required' => true,
                'achievement_id' => $badge->getKey(),
            ]);

            // Второе дело держит этап открытым: значок за материал не должен
            // ждать конца этапа.
            $stage->tasks()->create([
                'position' => 1, 'text' => 'Отработать смену', 'confirm_by' => 'responsible', 'is_required' => true,
            ]);
        });

        Enrollment::query()->create([
            'user_id' => $trainee->getKey(),
            'course_id' => $course->getKey(),
            'enrolled_at' => now(),
            'completed_at' => now(),
        ]);

        $this->actingAs($trainee)
            ->getJson(route('onboarding.my'))
            ->assertOk()
            ->assertJsonPath('data.stages.0.status', 'in_progress');

        $this->actingAs($trainee)
            ->getJson(route('profile.achievements'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Знаток кассы');
    }

    public function test_looking_at_the_map_again_does_not_hand_out_a_second_copy(): void
    {
        $badge = Achievement::query()->create(['title' => 'Один на всех']);
        $trainee = $this->trainee();

        // Один значок на двух шагах сразу: у человека он всё равно один.
        $this->journeyFor($trainee, stages: 2, prepare: function (OnboardingTemplate $template) use ($badge): void {
            $template->stages()->get()->each->update(['achievement_id' => $badge->getKey()]);
        });

        $this->travelTo(Carbon::now()->addDays(3));

        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();
        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();
        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();

        $this->assertSame(1, AchievementAward::query()->where('user_id', $trainee->getKey())->count());
    }

    public function test_a_badge_is_dated_by_the_day_the_step_was_closed(): void
    {
        $badge = Achievement::query()->create(['title' => 'Вовремя']);
        $trainee = $this->trainee();

        $this->travelTo(Carbon::parse('2026-11-02 09:00'));

        $this->journeyFor($trainee, stages: 1, prepare: function (OnboardingTemplate $template) use ($badge): void {
            $template->stages()->first()->update(['achievement_id' => $badge->getKey()]);
        });

        // Заметили через неделю — день заслуги от этого не съезжает.
        $this->travelTo(Carbon::parse('2026-11-09 09:00'));

        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();

        $award = AchievementAward::query()->sole();
        $stage = OnboardingJourney::query()->sole()->stages->first();

        $this->assertTrue($award->awarded_at->equalTo($stage->completed_at));
    }

    public function test_the_template_hands_its_badges_to_the_personal_route(): void
    {
        $stageBadge = Achievement::query()->create(['title' => 'За этап']);
        $quizBadge = Achievement::query()->create(['title' => 'За тест']);
        $trainee = $this->trainee();

        [$journey] = $this->journeyFor($trainee, stages: 1, prepare: function (OnboardingTemplate $template) use ($stageBadge, $quizBadge): void {
            $stage = $template->stages()->first();

            $stage->update([
                'achievement_id' => $stageBadge->getKey(),
                'quiz_achievement_id' => $quizBadge->getKey(),
            ]);

            $this->quizFor($stage);
        });

        $copy = $journey->stages->first();

        $this->assertSame($stageBadge->getKey(), $copy->achievement_id);
        $this->assertSame($quizBadge->getKey(), $copy->quiz_achievement_id);
    }

    public function test_a_passed_stage_quiz_hands_out_its_own_badge(): void
    {
        $quizBadge = Achievement::query()->create(['title' => 'Сдал с первого раза']);
        $trainee = $this->trainee();

        [$journey] = $this->journeyFor($trainee, stages: 1, prepare: function (OnboardingTemplate $template) use ($quizBadge): void {
            $stage = $template->stages()->first();
            $stage->update(['quiz_achievement_id' => $quizBadge->getKey()]);
            $this->quizFor($stage);
        });

        $stage = $journey->stages->first();
        $quiz = $this->journeyQuizOf($stage);

        $this->actingAs($trainee)
            ->postJson(route('onboarding.quiz.submit', $stage), [
                'answers' => $this->answersFor($quiz),
            ])
            ->assertCreated();

        $this->actingAs($trainee)
            ->getJson(route('profile.achievements'))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Сдал с первого раза');
    }

    public function test_an_unfinished_step_hands_out_nothing(): void
    {
        $badge = Achievement::query()->create(['title' => 'Рано']);
        $trainee = $this->trainee();

        $this->journeyFor($trainee, stages: 1, prepare: function (OnboardingTemplate $template) use ($badge): void {
            $template->stages()->first()->tasks()->create([
                'position' => 1,
                'text' => 'Отработать смену',
                'confirm_by' => 'responsible',
                'is_required' => true,
                'achievement_id' => $badge->getKey(),
            ]);
        });

        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();

        $this->assertDatabaseCount('achievement_user', 0);
    }
}
