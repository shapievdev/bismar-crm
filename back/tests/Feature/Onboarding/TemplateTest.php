<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\OnboardingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class TemplateTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_managing_requires_the_permission(): void
    {
        $this->actingAs($this->userWith())
            ->getJson(route('onboarding.manage.templates.index'))
            ->assertForbidden();

        $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.templates.index'))
            ->assertOk();
    }

    public function test_a_template_is_created_as_a_draft(): void
    {
        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.templates.store'), ['title' => 'Маршрут продавца'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_an_empty_template_cannot_be_published(): void
    {
        $template = OnboardingTemplate::factory()->draft()->create();

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.templates.status', $template), ['status' => 'published'])
            ->assertConflict();
    }

    public function test_a_stage_is_saved_with_its_content(): void
    {
        $template = OnboardingTemplate::factory()->draft()->create();
        $mentorMeetingWith = $this->userWith();

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.stages.save', $template), [
                'title' => 'Компания: история и ценности',
                'day_offset' => 1,
                'meetings' => [[
                    'topic' => 'Знакомство с командой',
                    'format' => 'offline',
                    'place' => 'Торговый зал',
                    'with_user_id' => $mentorMeetingWith->getKey(),
                ]],
                'tasks' => [
                    ['text' => 'Прочитать правила зала', 'confirm_by' => 'self'],
                    ['text' => 'Собрать заказ при наставнике', 'confirm_by' => 'responsible'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Компания: история и ценности')
            ->assertJsonCount(1, 'data.meetings')
            ->assertJsonCount(2, 'data.tasks');
    }

    public function test_a_meeting_needs_exactly_one_counterpart(): void
    {
        $template = OnboardingTemplate::factory()->draft()->create();

        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.stages.save', $template), [
                'title' => 'День 2',
                'day_offset' => 2,
                'meetings' => [['topic' => 'Ни с кем', 'format' => 'online']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meetings.0.with_user_id');
    }

    public function test_duplicating_copies_stages_and_quiz_questions(): void
    {
        $template = OnboardingTemplate::factory()->withStages(2)->create();
        $this->quizFor($template->stages()->first());

        $response = $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.templates.duplicate', $template))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $copy = OnboardingTemplate::query()->findOrFail((int) $response->json('data.id'));

        $this->assertSame(2, $copy->stages()->count());
        $this->assertNotNull($copy->stages()->first()->quiz);
        $this->assertSame(1, $copy->stages()->first()->quiz->questions()->count());
    }

    public function test_deleting_a_template_does_not_touch_what_was_applied(): void
    {
        $employee = $this->trainee();
        [, $template] = $this->journeyFor($employee, stages: 2);

        // Трафарет убрали — снятые с него этапы остались на своих местах.
        $this->actingAs($this->hr())
            ->deleteJson(route('onboarding.manage.templates.destroy', $template))
            ->assertNoContent();

        $this->actingAs($employee)
            ->getJson(route('onboarding.my'))
            ->assertOk()
            ->assertJsonCount(2, 'data.stages');
    }

    public function test_a_deleted_stage_leaves_applied_routes_alone(): void
    {
        $employee = $this->trainee();
        [, $template] = $this->journeyFor($employee, stages: 2);

        $first = $template->stages()->first();

        $this->actingAs($this->hr())
            ->deleteJson(route('onboarding.manage.stages.destroy', [$template, $first]))
            ->assertNoContent();

        // У того, кому шаблон уже наложили, этап остался: это его копия.
        $this->actingAs($employee)
            ->getJson(route('onboarding.my'))
            ->assertOk()
            ->assertJsonCount(2, 'data.stages');

        // А новое наложение убранный этап уже не приносит.
        $next = $this->trainee();
        $this->actingAs($this->hr())
            ->postJson(route('onboarding.manage.employees.templates.apply', [$next, $template]), ['anchor' => 'today'])
            ->assertOk()
            ->assertJsonCount(1, 'data.stages');
    }
}
