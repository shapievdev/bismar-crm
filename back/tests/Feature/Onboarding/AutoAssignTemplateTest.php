<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Actions\Onboarding\AutoAssignTemplate;
use App\Actions\User\CreateUser;
use App\Data\User\NewUserData;
use App\Enums\OnboardingTemplateStatus;
use App\Enums\Permission;
use App\Models\OnboardingJourney;
use App\Models\OnboardingTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class AutoAssignTemplateTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    private function createEmployee(?string $title = 'Продавец консультант', ?string $hired = null): User
    {
        Queue::fake();

        return app(CreateUser::class)->handle(NewUserData::fromArray([
            'last_name' => 'Тестов', 'first_name' => 'Сотрудник',
            'phone' => '+79990009977', 'password' => 'Password2026!',
            'job_title' => $title, 'hired_at' => $hired,
        ]));
    }

    public function test_creation_through_staff_api_automatically_copies_matching_published_template(): void
    {
        Queue::fake();
        $template = OnboardingTemplate::factory()->withStages(2)->create(['job_title' => 'Продавец консультант']);
        $response = $this->actingAs($this->administrator())->postJson(route('users.store'), [
            'last_name' => 'Новый', 'first_name' => 'Сотрудник', 'phone' => '+79990009977',
            'password' => 'Password2026!', 'password_confirmation' => 'Password2026!', 'job_title' => 'Продавец консультант',
        ])->assertCreated();
        $journey = OnboardingJourney::query()->where('user_id', $response->json('data.id'))->firstOrFail();
        $this->assertSame(2, $journey->stages()->count());
        $this->assertSame(now()->toDateString(), $journey->started_at->toDateString());
        $this->assertSame([$template->id], $journey->stages()->reorder()->distinct()->pluck('template_id')->all());
        $template->stages()->first()->update(['title' => 'Изменили шаблон']);
        $this->assertSame('Этап 1', $journey->stages()->orderBy('position')->first()->title);
    }

    public function test_optional_blank_binding_never_assigns_a_template(): void
    {
        OnboardingTemplate::factory()->withStages()->create(['job_title' => null]);
        $employee = $this->createEmployee();
        $this->assertDatabaseMissing('onboarding_journeys', ['user_id' => $employee->id]);
    }

    public function test_no_employee_title_means_no_autoassignment(): void
    {
        OnboardingTemplate::factory()->withStages()->create(['job_title' => 'Продавец консультант']);
        $employee = $this->createEmployee(null);
        $this->assertDatabaseMissing('onboarding_journeys', ['user_id' => $employee->id]);
    }

    public function test_title_matching_ignores_case_and_extra_spaces_but_not_different_positions(): void
    {
        $match = OnboardingTemplate::factory()->withStages(1)->create(['job_title' => 'Продавец консультант']);
        OnboardingTemplate::factory()->withStages(1)->create(['job_title' => 'Старший продавец консультант']);
        $employee = $this->createEmployee('  ПРОДАВЕЦ   консультант  ');
        $journey = OnboardingJourney::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertSame($match->id, $journey->stages()->sole()->template_id);
    }

    public function test_drafts_archived_deleted_and_empty_templates_are_not_assigned(): void
    {
        OnboardingTemplate::factory()->draft()->withStages()->create(['job_title' => 'Продавец консультант']);
        OnboardingTemplate::factory()->withStages()->create(['job_title' => 'Продавец консультант', 'status' => OnboardingTemplateStatus::Archived]);
        OnboardingTemplate::factory()->create(['job_title' => 'Продавец консультант']);
        OnboardingTemplate::factory()->withStages()->create(['job_title' => 'Продавец консультант'])->delete();
        $employee = $this->createEmployee();
        $this->assertDatabaseMissing('onboarding_journeys', ['user_id' => $employee->id]);
    }

    public function test_only_newest_eligible_published_template_is_applied(): void
    {
        OnboardingTemplate::factory()->withStages(3)->create(['job_title' => 'Продавец консультант']);
        $latest = OnboardingTemplate::factory()->withStages(1)->create(['job_title' => 'Продавец консультант', 'version' => 2]);
        OnboardingTemplate::factory()->draft()->withStages(2)->create(['job_title' => 'Продавец консультант', 'version' => 3]);
        $employee = $this->createEmployee();
        $stage = OnboardingJourney::query()->where('user_id', $employee->id)->firstOrFail()->stages()->sole();
        $this->assertSame($latest->id, $stage->template_id);
        $this->assertSame(2, $stage->template_version);
    }

    public function test_any_existing_stage_blocks_autoassignment_even_if_it_was_completed(): void
    {
        Queue::fake();
        OnboardingTemplate::factory()->withStages()->create(['job_title' => 'Продавец консультант']);
        $employee = User::factory()->create(['job_title' => 'Продавец консультант']);
        $journey = OnboardingJourney::create(['user_id' => $employee->id]);
        $stage = $journey->stages()->create(['title' => 'Личный этап', 'position' => 1, 'day_offset' => 1, 'completed_at' => now()]);
        $this->assertNull(app(AutoAssignTemplate::class)->handle($employee, $this->administrator()));
        $this->assertSame(1, $journey->stages()->count());
        $this->assertSame('Личный этап', $stage->fresh()->title);
        $this->assertNotNull($stage->fresh()->completed_at);
    }

    public function test_empty_journey_can_receive_a_template_but_repeated_assignment_is_noop(): void
    {
        Queue::fake();
        OnboardingTemplate::factory()->withStages(2)->create(['job_title' => 'Продавец консультант']);
        $employee = User::factory()->create(['job_title' => 'Продавец консультант']);
        $journey = OnboardingJourney::create(['user_id' => $employee->id]);
        $actor = $this->administrator();
        $action = app(AutoAssignTemplate::class);
        $this->assertNotNull($action->handle($employee, $actor));
        $this->assertNull($action->handle($employee, $actor));
        $this->assertSame(2, $journey->stages()->count());
    }

    public function test_no_retroactive_assignment_when_title_or_template_changes(): void
    {
        $employee = $this->createEmployee('Кладовщик');
        OnboardingTemplate::factory()->withStages()->create(['job_title' => 'Продавец консультант']);
        $employee->update(['job_title' => 'Продавец консультант']);
        $this->assertDatabaseMissing('onboarding_journeys', ['user_id' => $employee->id]);
    }

    public function test_schedule_uses_hire_date_or_today_when_it_is_unknown(): void
    {
        OnboardingTemplate::factory()->withStages(1)->create(['job_title' => 'Продавец консультант']);
        $employee = $this->createEmployee(hired: '2026-01-12');
        $journey = OnboardingJourney::query()->where('user_id', $employee->id)->firstOrFail();
        $this->assertSame('2026-01-12', $journey->started_at->toDateString());
    }

    public function test_job_title_suggestions_use_onboarding_permission_and_exclude_dismissed_people(): void
    {
        User::factory()->create(['job_title' => 'Продавец']);
        User::factory()->create(['job_title' => 'Продавец']);
        User::factory()->dismissed()->create(['job_title' => 'Неактуальная должность']);
        $this->actingAs($this->userWith(Permission::ManageOnboarding))->getJson(route('onboarding.manage.job-titles'))
            ->assertOk()->assertJsonPath('data', ['Продавец']);
        $this->actingAs($this->learner())->getJson(route('onboarding.manage.job-titles'))->assertForbidden();
    }
}
