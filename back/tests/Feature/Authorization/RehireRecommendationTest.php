<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class RehireRecommendationTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    private function person(Department $department, ?bool $recommended): User
    {
        $person = User::factory()->create(['dismissed_at' => '2026-11-15 12:00:00', 'hired_at' => '2026-01-01', 'rehire_recommended' => $recommended]);
        $person->departments()->attach($department, ['role' => 'member']);

        return $person;
    }

    private function report(string $route = 'analytics.staff.dismissals', array $extra = []): string
    {
        return route($route, ['from' => '2026-11-01', 'to' => '2026-11-30', ...$extra]);
    }

    public function test_yes_and_no_are_saved_for_both_dismissal_variants(): void
    {
        $actor = $this->administrator();
        foreach (['send', 'failed-internship'] as $action) {
            foreach ([true, false] as $recommended) {
                $person = $this->learner();
                $this->actingAs($actor)->postJson(route('users.dismiss', $person), ['survey_action' => $action, 'rehire_recommended' => $recommended])
                    ->assertOk()->assertJsonPath('data.rehire_recommended', $recommended);
                $person->refresh();
                $this->assertSame($recommended, $person->rehire_recommended);
                $this->assertSame($actor->id, $person->rehire_recommended_by_id);
                $this->assertNotNull($person->rehire_recommended_at);
                $this->assertSame($action === 'send', $person->pendingExitSurvey() !== null);
            }
        }
    }

    public function test_old_unknown_decision_can_be_changed_without_creating_survey_or_restoring_access(): void
    {
        $person = User::factory()->dismissed()->create();
        $this->actingAs($this->administrator())->getJson(route('users.show', $person))->assertOk()->assertJsonPath('data.rehire_recommended', null);
        $this->putJson(route('users.rehire.update', $person), ['rehire_recommended' => false])->assertOk()->assertJsonPath('data.rehire_recommended', false);
        $this->assertDatabaseCount('employee_exit_surveys', 0);
        $this->actingAs($person->fresh())->getJson(route('auth.user'))->assertUnauthorized();
    }

    public function test_editing_updates_snapshot_and_reinstatement_clears_decision(): void
    {
        $person = $this->learner();
        $this->actingAs($this->administrator())->postJson(route('users.dismiss', $person), ['rehire_recommended' => true])->assertOk();
        $survey = $person->fresh()->pendingExitSurvey();
        $survey->update(['answers' => ['reason' => 'Причина сотрудника']]);
        $this->putJson(route('users.rehire.update', $person), ['rehire_recommended' => false])->assertOk();
        $this->assertSame(false, $survey->fresh()->employee['rehire_recommended']);
        $this->assertSame('Причина сотрудника', $survey->fresh()->answers['reason']);
        $this->deleteJson(route('users.reinstate', $person))->assertOk()->assertJsonPath('data.rehire_recommended', null);
        $this->assertNull($person->fresh()->rehire_recommended_at);
        $this->assertNull($person->fresh()->rehire_recommended_by_id);
        $this->postJson(route('users.dismiss', $person), [])->assertOk()->assertJsonPath('data.rehire_recommended', null);
    }

    public function test_permissions_standing_and_employment_rules_are_enforced(): void
    {
        $person = User::factory()->dismissed()->create();
        $url = route('users.rehire.update', $person);
        $this->actingAs($this->userWith(Permission::ViewUsers))->putJson($url, ['rehire_recommended' => true])->assertForbidden();
        $this->actingAs($this->userWith(Permission::ManageUsers))->putJson($url, ['rehire_recommended' => true])->assertConflict();
        $this->actingAs($this->administrator())->putJson(route('users.rehire.update', $this->learner()), ['rehire_recommended' => true])->assertConflict();
        $this->putJson($url, ['rehire_recommended' => 'maybe'])->assertUnprocessable();
        $this->assertNull($person->fresh()->rehire_recommended);
    }

    public function test_statistics_distinguish_yes_no_and_unknown_and_list_only_yes(): void
    {
        $d = Department::factory()->create();
        $yes = $this->person($d, true);
        $no = $this->person($d, false);
        $unknown = $this->person($d, null);
        $this->person($d, true)->forceFill(['dismissed_at' => '2026-10-31'])->save();
        $this->person($d, true)->forceFill(['dismissed_at' => null])->save();
        $this->actingAs($this->administrator())->getJson($this->report())->assertOk()->assertJsonPath('data.summary.left', 3)
            ->assertJsonPath('data.summary.rehire_recommended_count', 1)->assertJsonPath('data.summary.rehire_not_recommended_count', 1)
            ->assertJsonPath('data.summary.rehire_unset_count', 1)->assertJsonMissing(['name' => $yes->name]);
        $this->getJson($this->report('analytics.staff.dismissals.rehire'))->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $yes->id)->assertJsonMissing(['name' => $no->name])->assertJsonMissing(['name' => $unknown->name]);
        $this->getJson($this->report('analytics.staff.dismissals.rehire', ['from' => '2026-12-01', 'to' => '2026-12-31']))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_list_respects_scope_and_hides_other_department_names(): void
    {
        $own = Department::factory()->create();
        $other = Department::factory()->create();
        $person = $this->person($own, true);
        $person->departments()->attach($other, ['role' => 'member']);
        $secret = $this->person($other, true);
        $viewer = $this->userWith(Permission::ViewStaffReport);
        $viewer->departments()->attach($own, ['role' => 'member']);
        $this->actingAs($viewer)->getJson($this->report('analytics.staff.dismissals.rehire'))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $person->id)->assertJsonPath('data.0.departments', [$own->name])->assertJsonMissing(['name' => $secret->name]);
        $this->getJson($this->report('analytics.staff.dismissals.rehire', ['departments' => [$other->id]]))->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($this->learner())->getJson($this->report('analytics.staff.dismissals.rehire'))->assertForbidden();
    }

    public function test_list_is_paginated_and_searchable_by_full_name(): void
    {
        $d = Department::factory()->create();
        for ($i = 0; $i < 26; $i++) {
            $this->person($d, true);
        }
        $match = User::where('rehire_recommended', true)->first();
        $match->forceFill(['last_name' => 'Иванова', 'first_name' => 'Анна', 'middle_name' => null])->save();
        $this->actingAs($this->administrator())->getJson($this->report('analytics.staff.dismissals.rehire'))->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 26);
        $this->getJson($this->report('analytics.staff.dismissals.rehire', ['page' => 2]))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->report('analytics.staff.dismissals.rehire', ['search' => 'иванова анна']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $match->id);
    }
}
