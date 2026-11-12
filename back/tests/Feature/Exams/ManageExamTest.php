<?php

declare(strict_types=1);

namespace Tests\Feature\Exams;

use App\Enums\DepartmentRole;
use App\Enums\Permission;
use App\Jobs\SendPush;
use App\Models\Department;
use App\Models\Exam;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Ведение экзаменов: право, публикация, назначение, результаты.
 */
final class ManageExamTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_managing_requires_the_permission(): void
    {
        $this->actingAs($this->userWith())
            ->getJson(route('exams.manage.index'))
            ->assertForbidden();

        $this->actingAs($this->keeper())
            ->getJson(route('exams.manage.index'))
            ->assertOk();
    }

    public function test_an_exam_is_created_as_a_draft_with_its_own_slug(): void
    {
        $this->actingAs($this->keeper())
            ->postJson(route('exams.manage.store'), ['title' => 'Экзамен по охране труда'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.slug', 'ekzamen-po-oxrane-truda');
    }

    public function test_an_exam_without_questions_cannot_be_published(): void
    {
        $exam = Exam::factory()->draft()->create();

        $this->actingAs($this->keeper())
            ->postJson(route('exams.manage.status', $exam), ['status' => 'published'])
            ->assertConflict();

        Quiz::factory()->forExam($exam)->withQuestions()->create();

        $this->actingAs($this->keeper())
            ->postJson(route('exams.manage.status', $exam), ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_the_exam_quiz_keeps_the_authors_passing_score(): void
    {
        $exam = Exam::factory()->draft()->create();

        $this->actingAs($this->keeper())
            ->putJson(route('exams.manage.quiz.save', $exam), [
                'title' => 'Вопросы',
                'passing_score' => 70,
                'questions' => [[
                    'text' => 'Сколько будет дважды два?',
                    'type' => 'single',
                    'points' => 1,
                    'options' => [
                        ['text' => 'Четыре', 'is_correct' => true],
                        ['text' => 'Пять', 'is_correct' => false],
                    ],
                ]],
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.passing_score', 70);
    }

    public function test_assigning_resolves_departments_to_people_and_skips_the_dismissed(): void
    {
        Queue::fake();

        $exam = Exam::factory()->create();
        $department = Department::factory()->create();

        $member = $this->userWith();
        $member->departments()->attach($department, ['role' => DepartmentRole::Member->value]);

        $dismissed = $this->userWith();
        $dismissed->departments()->attach($department, ['role' => DepartmentRole::Member->value]);
        $dismissed->forceFill(['dismissed_at' => now()])->save();

        $named = $this->userWith();

        $this->actingAs($this->keeper())
            ->putJson(route('exams.manage.assignments.sync', $exam), [
                'users' => [$named->getKey()],
                'departments' => [$department->getKey()],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            [$member->getKey(), $named->getKey()],
            $exam->assignments()->pluck('user_id')->map(intval(...))->all(),
        );

        // Уведомляют новых назначенных — и только их.
        Queue::assertPushed(SendPush::class, 1);
    }

    public function test_a_person_removed_from_the_list_is_released(): void
    {
        $exam = Exam::factory()->create();
        $kept = $this->userWith();
        $released = $this->userWith();

        foreach ([$kept, $released] as $person) {
            $exam->assignments()->create(['user_id' => $person->getKey()]);
        }

        $this->actingAs($this->keeper())
            ->putJson(route('exams.manage.assignments.sync', $exam), ['users' => [$kept->getKey()]])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame([$kept->getKey()], $exam->assignments()->pluck('user_id')->map(intval(...))->all());
    }

    public function test_a_due_date_beyond_the_closing_is_rejected(): void
    {
        $exam = Exam::factory()->create(['closes_at' => now()->addWeek()]);

        $this->actingAs($this->keeper())
            ->putJson(route('exams.manage.assignments.sync', $exam), [
                'users' => [$this->userWith()->getKey()],
                'due_at' => now()->addMonth()->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('due_at');
    }

    public function test_results_list_everyone_assigned_including_those_who_never_sat(): void
    {
        $exam = Exam::factory()->create();
        $quiz = Quiz::factory()->forExam($exam)->withQuestions()->create();

        $sat = $this->userWith();
        $never = $this->userWith();

        foreach ([$sat, $never] as $person) {
            $exam->assignments()->create(['user_id' => $person->getKey()]);
        }

        $quiz->attempts()->create([
            'user_id' => $sat->getKey(),
            'score' => 100,
            'passed' => true,
            'answers' => [],
            'scores' => [],
            'completed_at' => now(),
            'review_status' => 'auto',
        ]);

        $response = $this->actingAs($this->keeper())
            ->getJson(route('exams.manage.results', $exam))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $rows = collect($response->json('data'))->keyBy('user.id');

        $this->assertTrue($rows[$sat->getKey()]['passed']);
        $this->assertSame(100, $rows[$sat->getKey()]['best_score']);
        $this->assertFalse($rows[$never->getKey()]['passed']);
        $this->assertSame(0, $rows[$never->getKey()]['attempts_spent']);
    }

    /** Ведёт экзамены — и ничего больше. */
    private function keeper(): User
    {
        return $this->userWith(Permission::ManageExams);
    }
}
