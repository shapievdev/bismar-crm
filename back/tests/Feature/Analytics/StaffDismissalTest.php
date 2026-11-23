<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\DismissalReason;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\EmployeeExitSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class StaffDismissalTest extends TestCase
{
    use MakesUsers, RefreshDatabase;

    private function leaver(Department $department, string $hire = '2026-09-01'): User
    {
        $person = User::factory()->create(['hired_at' => $hire, 'dismissed_at' => '2026-11-15 12:00:00', 'dismissal_reason' => DismissalReason::Own]);
        $person->departments()->attach($department, ['role' => 'member']);

        return $person;
    }

    private function survey(User $person, bool $submitted = true, ?string $dismissed = null): void
    {
        EmployeeExitSurvey::create(['user_id' => $person->id, 'dismissed_at' => $dismissed ?? $person->dismissed_at,
            'employee' => ['name' => $person->name], 'definition' => [], 'answers' => ['reason' => 'Условия работы'], 'submitted_at' => $submitted ? now() : null]);
    }

    private function url(array $extra = []): string
    {
        return route('analytics.staff.dismissals', ['from' => '2026-11-01', 'to' => '2026-11-30', ...$extra]);
    }

    public function test_counts_current_interviews_unique_people_and_three_month_boundary(): void
    {
        $d = Department::factory()->create();
        $first = $this->leaver($d);
        $this->survey($first);
        $this->survey($this->leaver($d, '2026-08-15'), false);
        $this->survey($this->leaver($d, '2026-01-01'), true, '2026-03-15');
        $first->departments()->attach(Department::factory()->create(), ['role' => 'member']);
        $this->leaver($d)->forceFill(['dismissed_at' => '2026-10-31 12:00:00'])->save();
        $this->actingAs($this->administrator())->getJson($this->url())->assertOk()
            ->assertJsonPath('data.summary.left', 3)->assertJsonPath('data.summary.interviews', 1)
            ->assertJsonPath('data.summary.interview_coverage', 33.3)->assertJsonPath('data.summary.early_left', 1)
            ->assertJsonPath('data.summary.early_share', 33.3)->assertJsonPath('data.summary.pending', 1)
            ->assertJsonPath('data.summary.without_survey', 1)->assertJsonPath('data.reasons.0.share', 100);
    }

    public function test_manual_values_are_persistent_and_isolated_by_period(): void
    {
        $d = Department::factory()->create();
        $this->survey($this->leaver($d));
        $this->leaver($d, '2026-01-01');
        $payload = ['retainable' => 1, 'conditions_assessment' => '4 из 5', 'department_values' => [['id' => $d->id, 'average_headcount' => 8, 'retainable' => 1]]];
        $this->actingAs($this->administrator())->putJson($this->url(), $payload)->assertOk()
            ->assertJsonPath('data.summary.retainable_share', 100)->assertJsonPath('data.departments.0.turnover', 25)
            ->assertJsonPath('data.departments.0.interview_coverage', 50);
        $this->putJson($this->url(), [...$payload, 'retainable' => '1'])->assertOk()->assertJsonPath('data.summary.retainable', 1);
        $this->getJson($this->url())->assertJsonPath('data.conditions_assessment', '4 из 5')->assertJsonPath('data.summary.retainable', 1);
        $this->getJson($this->url(['to' => '2026-11-29']))->assertJsonPath('data.summary.retainable', null);
        $this->putJson($this->url(), [...$payload, 'retainable' => 2])->assertUnprocessable();
        $this->putJson($this->url(), [...$payload, 'retainable' => 0])->assertOk()->assertJsonPath('data.summary.retainable', 0);
    }

    public function test_scope_and_write_permissions_cannot_be_bypassed(): void
    {
        $own = Department::factory()->create();
        $other = Department::factory()->create();
        $this->leaver($own);
        $this->leaver($other);
        $viewer = $this->userWith(Permission::ViewStaffReport);
        $viewer->departments()->attach($own, ['role' => 'member']);
        $this->actingAs($viewer)->getJson($this->url())->assertOk()->assertJsonPath('data.summary.left', 1)->assertJsonCount(1, 'data.departments');
        $this->getJson($this->url(['departments' => [$other->id]]))->assertOk()->assertJsonPath('data.summary.left', 0);
        $this->putJson($this->url(), [])->assertForbidden();
        $this->actingAs($this->learner())->getJson($this->url())->assertForbidden();
        $this->actingAs($this->userWith(Permission::ViewStaffReport, Permission::ManageUsers))->putJson($this->url(), [
            'retainable' => null, 'department_values' => [['id' => $other->id, 'retainable' => null, 'average_headcount' => 1]],
        ])->assertForbidden();
    }

    public function test_department_hierarchy_aggregates_people_once_and_hides_inaccessible_parents(): void
    {
        $root = Department::factory()->create(['name' => 'Компания']);
        $first = Department::factory()->create(['name' => 'Розница', 'parent_id' => $root->id]);
        $second = Department::factory()->create(['name' => 'Касса', 'parent_id' => $root->id]);
        $person = $this->leaver($first);
        $person->departments()->attach($second, ['role' => 'member']);
        $this->survey($person);
        $response = $this->actingAs($this->administrator())->getJson($this->url())->assertOk();
        $rows = collect($response->json('data.departments'))->keyBy('id');
        $this->assertSame(1, $rows[$root->id]['left']);
        $this->assertSame(1, $rows[$root->id]['interviews']);
        $this->assertSame(1, $rows[$first->id]['left']);
        $this->assertSame(1, $rows[$second->id]['left']);
        $this->assertSame($root->id, $rows[$first->id]['parent_id']);
        $viewer = $this->userWith(Permission::ViewStaffReport);
        $viewer->departments()->attach($first, ['role' => 'member']);
        $this->actingAs($viewer)->getJson($this->url())->assertOk()
            ->assertJsonCount(1, 'data.departments')->assertJsonPath('data.departments.0.parent_id', null)
            ->assertJsonMissing(['name' => 'Компания']);
    }

    public function test_empty_period_has_no_fake_percentages(): void
    {
        $this->actingAs($this->administrator())->getJson($this->url())->assertOk()->assertJsonPath('data.summary.left', 0)
            ->assertJsonPath('data.summary.interview_coverage', null)->assertJsonPath('data.summary.early_share', null);
    }
}
