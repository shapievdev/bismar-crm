<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Actions\Onboarding\DuplicateTemplate;
use App\Jobs\SendPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class TaskResponsiblesTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_each_assignee_sees_the_task_and_either_can_confirm_it_once(): void
    {
        $first = $this->userWith();
        $second = $this->userWith();
        $stageResponsible = $this->userWith();
        $employee = $this->employee();
        [$journey] = $this->journeyFor($employee, stages: 1, prepare: function ($template) use ($first, $second, $stageResponsible) {
            $stage = $template->stages()->first();
            $stage->update(['responsible_ids' => [$stageResponsible->id]]);
            $stage->tasks()->create(['position' => 1, 'text' => 'Практика', 'confirm_by' => 'responsible', 'responsible_ids' => [$first->id, $second->id]]);
        });
        $task = $journey->stages->first()->tasks->first();
        foreach ([$first, $second] as $person) {
            $this->actingAs($person)->getJson(route('onboarding.tasks.mine'))->assertOk()->assertJsonPath('data.0.id', $task->id);
            $this->getJson(route('onboarding.assigned.stages'))->assertOk()->assertJsonCount(1, 'data');
        }
        $this->actingAs($stageResponsible)->getJson(route('onboarding.tasks.mine'))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(route('onboarding.tasks.confirm', $task))->assertForbidden();
        $this->actingAs($this->userWith())->postJson(route('onboarding.tasks.confirm', $task))->assertForbidden();
        $this->actingAs($employee)->getJson(route('onboarding.my'))->assertOk()
            ->assertJsonPath('data.stages.0.items.0.meta.responsible_ids', [$first->id, $second->id])
            ->assertJsonCount(2, 'data.stages.0.items.0.meta.responsibles');
        $this->actingAs($second)->postJson(route('onboarding.tasks.confirm', $task))->assertOk();
        $this->assertSame($second->id, $task->fresh()->confirmed_by);
        $this->assertSame('completed', $journey->fresh()->status->value);
        $this->actingAs($first)->postJson(route('onboarding.tasks.confirm', $task))->assertConflict();
        $this->getJson(route('onboarding.tasks.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_template_and_personal_editors_save_lists_and_snapshot_them(): void
    {
        $first = $this->userWith();
        $second = $this->userWith();
        $employee = $this->employee();
        [$journey, $template] = $this->journeyFor($employee, stages: 1, prepare: function ($template) use ($first, $second) {
            $template->stages()->first()->tasks()->create(['position' => 1, 'text' => 'Старое', 'confirm_by' => 'responsible', 'responsible_ids' => [$first->id, $second->id]]);
        });
        $this->actingAs($this->hr())->getJson(route('onboarding.manage.people', ['search' => 'no-matching-name-xyz', 'selected' => [$first->id, $second->id]]))
            ->assertOk()->assertJsonCount(2, 'data');
        $stage = $template->stages()->first();
        $copy = app(DuplicateTemplate::class)->handle($template, $this->hr());
        $this->assertSame([$first->id, $second->id], $copy->stages()->first()->tasks()->first()->responsible_ids);
        $this->actingAs($this->hr())->postJson(route('onboarding.manage.stages.save', $template), [
            'id' => $stage->id, 'title' => $stage->title, 'day_offset' => 1,
            'tasks' => [['id' => $stage->tasks()->first()->id, 'text' => 'Новое', 'confirm_by' => 'responsible', 'responsible_ids' => [(string) $second->id]], ['text' => 'Ещё задание', 'confirm_by' => 'responsible', 'responsible_ids' => [$second->id]]],
        ])->assertCreated()->assertJsonPath('data.tasks.0.responsible_ids', [$second->id]);
        $personalStage = $journey->stages->first();
        $this->assertSame([$first->id, $second->id], $personalStage->tasks()->first()->responsible_ids);
        $this->postJson(route('onboarding.manage.employees.stages.save', $employee), [
            'id' => $personalStage->id, 'title' => $personalStage->title,
            'tasks' => [['id' => $personalStage->tasks()->first()->id, 'text' => 'Личное задание', 'confirm_by' => 'responsible', 'responsible_ids' => [$first->id]]],
        ])->assertOk();
        $this->assertSame([$first->id], $personalStage->tasks()->first()->responsible_ids);
        $this->assertSame([$second->id], $stage->tasks()->first()->responsible_ids);
        $this->postJson(route('onboarding.manage.employees.stages.save', $employee), [
            'id' => $personalStage->id, 'title' => $personalStage->title,
            'tasks' => [['text' => 'Ошибка', 'confirm_by' => 'responsible', 'responsible_ids' => [$first->id, $first->id]]],
        ])->assertUnprocessable();
    }

    public function test_all_task_responsibles_receive_stage_opening_notifications(): void
    {
        $first = $this->userWith();
        $second = $this->userWith();
        $employee = $this->employee();
        $this->journeyFor($employee, stages: 1, prepare: function ($template) use ($first, $second) {
            $template->stages()->first()->tasks()->create(['position' => 1, 'text' => 'Практика', 'confirm_by' => 'responsible', 'responsible_ids' => [$first->id, $second->id]]);
        });
        Queue::fake();
        $this->artisan('onboarding:remind', ['--employee' => $employee->id])->assertSuccessful();
        foreach ([$first, $second] as $person) {
            Queue::assertPushed(SendPush::class, fn ($job) => in_array($person->id, (fn () => $this->userIds)->call($job), true));
        }
    }
}
