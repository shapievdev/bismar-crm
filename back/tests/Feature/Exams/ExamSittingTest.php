<?php

declare(strict_types=1);

namespace Tests\Feature\Exams;

use App\Enums\AttestationStatus;
use App\Models\Exam;
use App\Models\ExamAssignment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\Exams\ExamSitting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Сдача экзамена: назначение, окно, таймер, попытки.
 */
final class ExamSittingTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_only_assigned_exams_are_listed(): void
    {
        [$mine] = $this->examFor($sitter = $this->someone());
        Exam::factory()->create(['title' => 'Чужой экзамен']);

        $this->actingAs($sitter)
            ->getJson(route('exams.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.exam.title', $mine->title);
    }

    public function test_a_draft_exam_is_invisible_even_to_the_assigned(): void
    {
        [$exam] = $this->examFor($sitter = $this->someone());
        $exam->update(['status' => 'draft']);

        $this->actingAs($sitter)->getJson(route('exams.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($sitter)->getJson(route('exams.show', $exam))->assertNotFound();
    }

    public function test_an_unassigned_person_gets_not_found_rather_than_forbidden(): void
    {
        [$exam] = $this->examFor($this->someone());

        $this->actingAs($this->someone())
            ->getJson(route('exams.show', $exam))
            ->assertNotFound();
    }

    public function test_the_header_comes_without_questions_and_start_brings_them_without_the_key(): void
    {
        [$exam] = $this->examFor($sitter = $this->someone());

        $this->actingAs($sitter)
            ->getJson(route('exams.show', $exam))
            ->assertOk()
            ->assertJsonMissingPath('data.exam.quiz.questions');

        $response = $this->actingAs($sitter)
            ->postJson(route('exams.start', $exam))
            ->assertOk();

        $questions = $response->json('quiz.questions');
        $this->assertNotEmpty($questions);

        foreach ($questions[0]['options'] as $option) {
            $this->assertArrayNotHasKey('is_correct', $option);
        }
    }

    public function test_a_closed_window_refuses_to_start(): void
    {
        [$exam] = $this->examFor($sitter = $this->someone());
        $exam->update(['opens_at' => now()->addDay()]);

        $this->actingAs($sitter)
            ->postJson(route('exams.start', $exam))
            ->assertConflict();

        $exam->update(['opens_at' => null, 'closes_at' => now()->subHour()]);

        $this->actingAs($sitter)
            ->postJson(route('exams.start', $exam))
            ->assertConflict();
    }

    public function test_submitting_without_starting_is_refused(): void
    {
        [$exam, $quiz] = $this->examFor($sitter = $this->someone());

        $this->actingAs($sitter)
            ->postJson(route('exams.submit', $exam), ['answers' => $this->correctAnswers($quiz)])
            ->assertConflict();
    }

    public function test_the_authors_passing_score_is_what_grades_the_attempt(): void
    {
        // Планка пятьдесят: один верный ответ из двух — сдано.
        [$exam, $quiz] = $this->examFor($sitter = $this->someone(), passingScore: 50);

        $this->actingAs($sitter)->postJson(route('exams.start', $exam))->assertOk();

        $answers = $this->correctAnswers($quiz);
        $wrongOption = $quiz->questions->last()->options->firstWhere('is_correct', false);
        $answers[$quiz->questions->last()->getKey()] = [$wrongOption->getKey()];

        $this->actingAs($sitter)
            ->postJson(route('exams.submit', $exam), ['answers' => $answers])
            ->assertCreated()
            ->assertJsonPath('data.score', 50)
            ->assertJsonPath('data.passed', true);
    }

    public function test_restarting_a_live_window_continues_the_same_attempt(): void
    {
        [$exam] = $this->examFor($sitter = $this->someone(), timeLimit: 30);

        $first = $this->actingAs($sitter)->postJson(route('exams.start', $exam))->json('window.expires_at');
        $second = $this->actingAs($sitter)->postJson(route('exams.start', $exam))->json('window.expires_at');

        $this->assertSame($first, $second);
        $this->assertSame(0, QuizAttempt::query()->count());
    }

    public function test_an_expired_window_writes_the_attempt_off(): void
    {
        [$exam, $quiz] = $this->examFor($sitter = $this->someone(), timeLimit: 30);

        $this->actingAs($sitter)->postJson(route('exams.start', $exam))->assertOk();

        $this->travel(31)->minutes();

        $this->actingAs($sitter)
            ->postJson(route('exams.submit', $exam), ['answers' => $this->correctAnswers($quiz)])
            ->assertConflict();

        $attempt = QuizAttempt::query()->sole();
        $this->assertSame(0, $attempt->score);
        $this->assertFalse($attempt->passed);
        $this->assertNotNull($attempt->started_at);
    }

    public function test_a_submission_within_the_grace_seconds_is_accepted(): void
    {
        [$exam, $quiz] = $this->examFor($sitter = $this->someone(), timeLimit: 30);

        $this->actingAs($sitter)->postJson(route('exams.start', $exam))->assertOk();

        // Таймер вышел, но сетевая поблажка ещё держит дверь.
        $this->travel(30 * 60 + ExamSitting::GRACE_SECONDS - 5)->seconds();

        $this->actingAs($sitter)
            ->postJson(route('exams.submit', $exam), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated()
            ->assertJsonPath('data.passed', true);
    }

    public function test_spent_attempts_count_the_written_off_ones(): void
    {
        [$exam] = $this->examFor($sitter = $this->someone(), timeLimit: 30, maxAttempts: 1);

        $this->actingAs($sitter)->postJson(route('exams.start', $exam))->assertOk();
        $this->travel(31)->minutes();

        // Просроченная и не отправленная попытка списывается при следующем
        // «Начать» — и оказывается последней разрешённой.
        $this->actingAs($sitter)
            ->postJson(route('exams.start', $exam))
            ->assertConflict();

        $this->assertSame(1, QuizAttempt::query()->count());
    }

    public function test_attempts_run_out(): void
    {
        [$exam, $quiz] = $this->examFor($sitter = $this->someone(), maxAttempts: 1);

        $this->actingAs($sitter)->postJson(route('exams.start', $exam))->assertOk();
        $this->actingAs($sitter)
            ->postJson(route('exams.submit', $exam), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated();

        $this->actingAs($sitter)
            ->postJson(route('exams.start', $exam))
            ->assertConflict();
    }

    public function test_an_attestation_exam_waits_for_the_examiner_and_lands_in_their_queue(): void
    {
        $examiner = $this->someone();
        [$exam, $quiz] = $this->examFor($sitter = $this->someone());
        $quiz->update(['kind' => 'attestation', 'examiner_id' => $examiner->getKey()]);

        $this->actingAs($sitter)->postJson(route('exams.start', $exam))->assertOk();
        $this->actingAs($sitter)
            ->postJson(route('exams.submit', $exam), ['answers' => $this->correctAnswers($quiz)])
            ->assertCreated()
            ->assertJsonPath('data.review_status', AttestationStatus::Pending->value);

        // Работа видна проверяющему в той же очереди, что и аттестации уроков.
        $this->actingAs($examiner)
            ->getJson(route('lms.attestations.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_ones_own_attempt_is_reviewable_and_somebody_elses_is_not_found(): void
    {
        [$exam, $quiz] = $this->examFor($sitter = $this->someone());
        $this->assign($exam, $other = $this->someone());

        $this->actingAs($sitter)->postJson(route('exams.start', $exam))->assertOk();
        $attemptId = $this->actingAs($sitter)
            ->postJson(route('exams.submit', $exam), ['answers' => $this->correctAnswers($quiz)])
            ->json('data.id');

        $this->actingAs($sitter)
            ->getJson(route('exams.attempts.show', [$exam, $attemptId]))
            ->assertOk();

        $this->actingAs($other)
            ->getJson(route('exams.attempts.show', [$exam, $attemptId]))
            ->assertNotFound();
    }

    /**
     * Опубликованный экзамен с вопросами, назначенный этому человеку.
     *
     * @return array{0: Exam, 1: Quiz}
     */
    private function examFor(
        User $sitter,
        int $passingScore = 100,
        ?int $timeLimit = null,
        ?int $maxAttempts = null,
    ): array {
        $exam = Exam::factory()->create(['time_limit_minutes' => $timeLimit]);

        $quiz = Quiz::factory()
            ->forExam($exam)
            ->withQuestions(2)
            ->create(['passing_score' => $passingScore, 'max_attempts' => $maxAttempts]);

        $this->assign($exam, $sitter);

        return [$exam, $quiz->load('questions.options')];
    }

    private function assign(Exam $exam, User $sitter): ExamAssignment
    {
        return ExamAssignment::create([
            'exam_id' => $exam->getKey(),
            'user_id' => $sitter->getKey(),
        ]);
    }

    private function someone(): User
    {
        return $this->userWith();
    }

    /**
     * @return array<int, list<int>>
     */
    private function correctAnswers(Quiz $quiz): array
    {
        $answers = [];

        foreach ($quiz->questions as $question) {
            $answers[$question->getKey()] = [$question->options->firstWhere('is_correct', true)->getKey()];
        }

        return $answers;
    }
}
