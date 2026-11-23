<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Actions\User\ChangeEmployment;
use App\Enums\Permission;
use App\Models\EmployeeExitSurvey;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class ExitSurveyTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    private function dismiss(?User $user = null): User
    {
        $user ??= $this->learner();
        app(ChangeEmployment::class)->dismiss($user, $this->administrator());

        return $user->fresh();
    }

    private function payload(User $user): array
    {
        $survey = $user->pendingExitSurvey();
        $answers = [];
        foreach ($survey->definition['fields'] as $field) {
            $answers[$field['id']] = match ($field['type']) {
                'radio', 'select' => $field['options'][0],
                'date' => now()->toDateString(),
                default => 'Тестовый ответ',
            };
        }

        return ['assignment_id' => $survey->id, 'answers' => $answers];
    }

    public function test_failed_internship_closes_access_without_creating_survey(): void
    {
        $person = $this->learner();
        $this->actingAs($this->administrator())->postJson(route('users.dismiss', $person), [
            'survey_action' => 'failed-internship',
        ])->assertOk()->assertJsonPath('data.dismissal_reason', 'probation-failed')->assertJsonPath('data.exit_survey_status', null);
        $this->assertDatabaseCount('employee_exit_surveys', 0);
        $this->assertNotNull($person->fresh()->dismissed_at);
        $this->actingAs($person->fresh())->getJson(route('auth.user'))->assertUnauthorized();
        $this->postJson(route('auth.login'), ['phone' => $person->phone, 'password' => 'password'])->assertUnprocessable();
    }

    public function test_repeated_dismissal_without_survey_does_not_show_old_invitation(): void
    {
        $person = $this->dismiss();
        $actor = $this->administrator();
        app(ChangeEmployment::class)->reinstate($person, $actor);
        $this->actingAs($actor)->postJson(route('users.dismiss', $person), ['survey_action' => 'failed-internship'])
            ->assertOk()->assertJsonPath('data.exit_survey_status', null);
        $this->assertDatabaseCount('employee_exit_surveys', 1);
    }

    public function test_corrected_dismissal_date_keeps_completed_interview_in_the_same_event(): void
    {
        $person = $this->dismiss();
        $survey = $person->pendingExitSurvey();
        $survey->update(['submitted_at' => now()]);
        $day = CarbonImmutable::now()->subDay()->startOfDay();
        app(ChangeEmployment::class)->correctDismissalDate($person, $this->administrator(), $day);
        $this->assertSame($day->toDateString(), $survey->fresh()->dismissed_at->toDateString());
        $this->actingAs($this->administrator())->getJson(route('users.show', $person))
            ->assertOk()->assertJsonPath('data.exit_survey_status', 'submitted');
    }

    public function test_only_new_dismissals_get_an_assignment(): void
    {
        $this->assertNotNull($this->dismiss()->pendingExitSurvey());
        $old = User::factory()->dismissed()->create();
        $this->assertNull($old->pendingExitSurvey());
        $this->actingAs($old)->getJson(route('exit-survey.show'))->assertForbidden();
    }

    public function test_pending_person_can_sign_in_but_cannot_access_any_business_data_even_as_admin(): void
    {
        $user = $this->dismiss($this->administrator());
        $this->postJson(route('auth.login'), ['phone' => $user->phone, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.exit_survey_pending', true)
            ->assertJsonPath('data.permissions', [])->assertJsonPath('data.level', 'user')->assertJsonMissingPath('data.email');
        $this->actingAs($user)->getJson(route('auth.user'))->assertOk()->assertJsonPath('data.exit_survey_pending', true);
        foreach (['lms.courses.index', 'chat.conversations.index', 'users.index', 'analytics.exit-surveys.index'] as $route) {
            $this->actingAs($user)->getJson(route($route))->assertForbidden()->assertJsonPath('code', 'EXIT_SURVEY_REQUIRED');
        }
        $this->actingAs($user)->getJson(route('exit-survey.show'))->assertOk()->assertJsonCount(16, 'data.definition.fields');
    }

    public function test_submission_saves_answers_and_closes_session_and_future_logins(): void
    {
        $user = $this->dismiss();
        $payload = $this->payload($user);
        $payload['answers']['care'] = 'other';
        $payload['answers']['care_other'] = 'Пожелание сотрудника';
        $this->actingAs($user)->postJson(route('exit-survey.store'), $payload)->assertOk();
        $saved = EmployeeExitSurvey::query()->firstOrFail();
        $this->assertNotNull($saved->submitted_at);
        $this->assertSame('Пожелание сотрудника', $saved->answers['care_other']);
        $this->assertGuest('web');
        $this->actingAs($user->fresh())->getJson(route('auth.user'))->assertUnauthorized();
        $this->postJson(route('auth.login'), ['phone' => $user->phone, 'password' => 'password'])->assertUnprocessable();
        $this->actingAs($user->fresh())->postJson(route('exit-survey.store'), $payload)->assertConflict();
        $this->assertDatabaseCount('employee_exit_surveys', 1);
    }

    public function test_invalid_answers_keep_survey_pending(): void
    {
        $user = $this->dismiss();
        $payload = $this->payload($user);
        $payload['answers']['employer'] = 'forged';
        $payload['answers']['care'] = 'other';
        unset($payload['answers']['full_name']);
        $this->actingAs($user)->postJson(route('exit-survey.store'), $payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.full_name', 'answers.employer', 'answers.care_other']);
        $this->assertNotNull($user->pendingExitSurvey());
    }

    public function test_cannot_submit_someone_elses_assignment(): void
    {
        $user = $this->dismiss();
        $other = $this->dismiss();
        $this->actingAs($user)->postJson(route('exit-survey.store'), $this->payload($other))->assertUnprocessable();
        $this->assertNull($other->pendingExitSurvey()->submitted_at);
    }

    public function test_reinstatement_cancels_pending_and_repeated_dismissal_gets_new_assignment(): void
    {
        $user = $this->dismiss();
        $old = $this->payload($user);
        app(ChangeEmployment::class)->reinstate($user, $this->administrator());
        $this->assertNotNull(EmployeeExitSurvey::query()->firstOrFail()->cancelled_at);
        $this->assertNull($user->fresh()->pendingExitSurvey());
        $this->actingAs($user->fresh())->getJson(route('exit-survey.show'))->assertForbidden();
        $user = $this->dismiss($user->fresh());
        $this->actingAs($user)->postJson(route('exit-survey.store'), $old)->assertUnprocessable();
        $this->assertDatabaseCount('employee_exit_surveys', 2);
    }

    public function test_results_and_pending_names_are_only_for_admin_and_full_staff_manager(): void
    {
        $pending = $this->dismiss();
        $submitted = $this->dismiss();
        $this->actingAs($submitted)->postJson(route('exit-survey.store'), $this->payload($submitted))->assertOk();
        $survey = $submitted->exitSurveys()->firstOrFail();
        foreach ([$this->administrator(), $this->userWith(Permission::ManageUsers, Permission::ViewUsers)] as $reader) {
            $this->actingAs($reader)->getJson(route('analytics.exit-surveys.index'))->assertOk()
                ->assertJsonPath('summary.pending', 1)->assertJsonPath('summary.submitted', 1)->assertJsonCount(2, 'data')->assertJsonCount(9, 'breakdowns');
            $this->actingAs($reader)->getJson(route('analytics.exit-surveys.show', $survey))->assertOk()->assertJsonPath('data.answers.full_name', 'Тестовый ответ');
            $this->actingAs($reader)->getJson(route('users.show', $pending))->assertOk()->assertJsonPath('data.exit_survey_status', 'pending');
        }
        foreach ([$this->learner(), $this->userWith(Permission::ViewUsers), $this->userWith(Permission::ViewAnalytics)] as $reader) {
            $this->actingAs($reader)->getJson(route('analytics.exit-surveys.index'))->assertForbidden();
            $this->actingAs($reader)->getJson(route('analytics.exit-surveys.show', $survey))->assertForbidden();
        }
    }

    public function test_pending_filter_and_department_filter_and_no_answers_in_list(): void
    {
        $pending = $this->dismiss();
        $submitted = $this->dismiss();
        $this->actingAs($submitted)->postJson(route('exit-survey.store'), $this->payload($submitted))->assertOk();
        $this->actingAs($this->administrator())->getJson(route('analytics.exit-surveys.index', ['status' => 'pending']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $pending->id)->assertJsonMissingPath('data.0.answers');
        $this->getJson(route('analytics.exit-surveys.index', ['department' => 'несуществующий']))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.pending', 0);
    }

    public function test_corrected_dismissal_date_is_prefilled(): void
    {
        $user = $this->dismiss();
        app(ChangeEmployment::class)->correctDismissalDate($user, $this->administrator(), CarbonImmutable::parse('2026-01-05'));
        $this->actingAs($user)->getJson(route('exit-survey.show'))->assertOk()->assertJsonPath('data.prefill.dismissed_on', '2026-01-05');
    }

    public function test_pending_login_does_not_authorize_messenger_sockets(): void
    {
        $user = $this->dismiss();
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
        require base_path('routes/channels.php');
        foreach (['private-users.'.$user->id, 'presence-presence.employees'] as $channel) {
            $this->actingAs($user)->postJson('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'])->assertForbidden();
        }
    }

    public function test_pending_employee_can_reset_a_forgotten_password(): void
    {
        Notification::fake();
        $user = $this->dismiss();
        $this->postJson(route('auth.password.forgot'), ['login' => $user->phone])->assertOk();
        Notification::assertSentTo($user, ResetPasswordLink::class);
        $token = Password::broker()->createToken($user);
        $this->postJson(route('auth.password.reset'), ['user' => $user->id, 'token' => $token,
            'password' => 'NewPassword2026!', 'password_confirmation' => 'NewPassword2026!'])->assertOk();
        $this->assertNotNull($user->fresh()->pendingExitSurvey());
    }

    public function test_submission_closes_other_database_sessions(): void
    {
        $user = $this->dismiss();
        config()->set('session.driver', 'database');
        DB::table('sessions')->insert([
            'id' => 'another-browser', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp,
        ]);
        $this->actingAs($user)->postJson(route('exit-survey.store'), $this->payload($user))->assertOk();
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_date_filter_accepts_an_upper_bound_without_a_lower_bound(): void
    {
        $user = $this->dismiss();
        $this->actingAs($this->administrator())->getJson(route('analytics.exit-surveys.index', ['to' => now()->toDateString()]))
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_search_is_case_insensitive_for_cyrillic_and_treats_percent_literally(): void
    {
        $user = $this->dismiss(User::factory()->create(['first_name' => 'Мария', 'last_name' => 'Тестова']));
        $this->actingAs($this->administrator())->getJson(route('analytics.exit-surveys.index', ['search' => 'мария']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $user->id);
        $this->getJson(route('analytics.exit-surveys.index', ['search' => '%']))->assertOk()->assertJsonCount(0, 'data');
    }
}
