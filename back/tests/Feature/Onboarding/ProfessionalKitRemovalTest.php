<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Achievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class ProfessionalKitRemovalTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_legacy_kit_does_not_block_learning_or_award_an_equipment_badge(): void
    {
        $employee = $this->employee();
        [$journey] = $this->journeyFor($employee, stages: 1, prepare: fn ($template) => $this->quizFor($template->stages()->first()));
        $badge = Achievement::create(['title' => 'Старый набор']);
        $journey->update(['requires_graduation' => true, 'graduation_achievement_id' => $badge->id]);
        $stage = $journey->stages->first();
        $this->actingAs($employee)->getJson(route('onboarding.my'))->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson(route('onboarding.quiz.submit', $stage), ['answers' => $this->answersFor($this->journeyQuizOf($stage))])->assertCreated();
        $this->getJson(route('onboarding.my'))->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.progress.percent', 100)
            ->assertJsonPath('data.next_action', null)->assertJsonMissingPath('data.graduation')
            ->assertJsonMissingPath('data.kit_items')->assertJsonMissingPath('data.requires_graduation');
        $this->assertDatabaseMissing('achievement_user', ['user_id' => $employee->id, 'achievement_id' => $badge->id]);
    }

    public function test_kit_can_no_longer_be_configured_or_issued(): void
    {
        $employee = $this->employee();
        [, $template] = $this->journeyFor($employee, stages: 1);
        $this->actingAs($this->hr());
        $this->postJson('/api/onboarding/manage/employees/'.$employee->id.'/graduate', ['what' => 'access'])->assertNotFound();
        $this->putJson(route('onboarding.manage.employees.settings', $employee), ['requires_graduation' => true, 'kit_items' => [['id' => 'badge']], 'kit_receipts' => ['badge' => ['confirmed_at' => now()]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['requires_graduation', 'kit_items', 'kit_receipts']);
        $this->putJson(route('onboarding.manage.templates.update', $template), ['title' => 'Программа', 'kit_items' => [['id' => 'badge']]])
            ->assertUnprocessable()->assertJsonValidationErrors('kit_items');
        $this->getJson(route('onboarding.manage.templates.show', $template))->assertOk()
            ->assertJsonMissingPath('data.kit_items')->assertJsonMissingPath('data.graduation_achievement_id');
    }

    public function test_retirement_finishes_only_completed_active_routes_and_preserves_receipts(): void
    {
        [$finished, $template] = $this->journeyFor($this->employee(), stages: 1);
        [$unfinished] = $this->journeyFor($this->employee(), stages: 1);
        [$paused] = $this->journeyFor($this->employee(), stages: 1);
        $empty = $this->emptyJourneyFor($this->employee());
        $receipts = ['badge' => ['label' => 'Бейдж', 'confirmed_at' => now()->toIso8601String()]];
        $finished->update(['requires_graduation' => true, 'kit_receipts' => $receipts]);
        $template->update(['requires_graduation' => true]);
        $finished->stages()->update(['completed_at' => now()]);
        $paused->stages()->update(['completed_at' => now()]);
        $paused->update(['status' => 'paused']);
        $migration = require database_path('migrations/2026_11_13_140000_retire_professional_kits.php');
        $migration->up();
        $this->assertSame('completed', $finished->fresh()->status->value);
        $this->assertNotNull($finished->fresh()->completed_at);
        $this->assertSame($receipts, $finished->fresh()->kit_receipts);
        $this->assertFalse($template->fresh()->requires_graduation);
        $this->assertSame('active', $unfinished->fresh()->status->value);
        $this->assertSame('paused', $paused->fresh()->status->value);
        $this->assertSame('active', $empty->fresh()->status->value);
        $migration->up();
        $this->assertSame($receipts, $finished->fresh()->kit_receipts);
    }
}
