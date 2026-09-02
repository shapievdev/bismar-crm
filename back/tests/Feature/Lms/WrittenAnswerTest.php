<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\Permission;
use App\Enums\QuestionType;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Ai\Embedder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Вопрос, на который отвечают своими словами.
 *
 * Проверяет ИИ: сравнивает написанное с эталоном автора по смыслу и зачитывает
 * вопрос, если схожесть выше порога (решение пользователя 2026-09-02). Ключ —
 * то есть сам эталон — сотруднику при этом не показывается.
 */
final class WrittenAnswerTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    private function author(): User
    {
        return $this->userWith(Permission::ViewCourses, Permission::UpdateCourses);
    }

    /**
     * Эмбеддинги подменяются: провайдер в тестах не нужен, а нужна управляемая
     * близость. Вектор собирается из слов ответа, поэтому у похожих текстов
     * совпадает больше координат — ровно то, что делает настоящая модель.
     */
    private function fakeEmbeddings(): void
    {
        config(['ai.api_key' => 'test-key', 'ai.embedding_model' => 'test-embeddings']);

        Http::fake(['*/v1/embeddings' => function (Request $request) {
            /** @var list<string> $inputs */
            $inputs = $request->data()['input'];

            return Http::response(['data' => array_map(static function (string $text): array {
                $vector = array_fill(0, Embedder::DIMENSIONS, 0.0);

                foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [] as $word) {
                    if ($word === '') {
                        continue;
                    }

                    $vector[abs(crc32($word)) % Embedder::DIMENSIONS] = 1.0;
                }

                return ['embedding' => $vector];
            }, $inputs)]);
        }]);
    }

    /**
     * @return array{Regulation, Quiz}
     */
    private function documentWithWrittenQuestion(string $expected): array
    {
        $document = Regulation::factory()->published()->create();

        $quiz = Quiz::factory()->create(['quizzable_type' => 'regulation', 'quizzable_id' => $document->id]);
        $quiz->questions()->create([
            'text' => 'Почему прибыль есть, а денег нет?',
            'type' => QuestionType::LongText,
            'expected_answer' => $expected,
            'points' => 1,
            'position' => 0,
        ]);

        return [$document, $quiz->refresh()];
    }

    /* ---------- Автор собирает такой вопрос ---------- */

    public function test_an_author_asks_for_an_answer_in_words(): void
    {
        $course = Course::factory()->withLessons(1)->create();
        $lesson = $course->lessons()->first();

        $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $lesson), [
                'title' => 'Проверка',
                'passing_score' => 100,
                'questions' => [[
                    'text' => 'Куда ушла прибыль?',
                    'type' => QuestionType::LongText->value,
                    'points' => 2,
                    'expected_answer' => 'В запасы и в дебиторку: товар лежит на складе, деньги у клиентов.',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.questions.0.type', QuestionType::LongText->value)
            // Эталон виден тому, кто правит: это его же ключ.
            ->assertJsonPath(
                'data.questions.0.expected_answer',
                'В запасы и в дебиторку: товар лежит на складе, деньги у клиентов.',
            )
            ->assertJsonCount(0, 'data.questions.0.options');
    }

    /** Без эталона сравнивать не с чем — такой вопрос не взять никогда. */
    public function test_a_written_question_without_a_reference_is_refused(): void
    {
        $course = Course::factory()->withLessons(1)->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $course->lessons()->first()), [
                'title' => 'Проверка',
                'passing_score' => 100,
                'questions' => [[
                    'text' => 'Куда ушла прибыль?',
                    'type' => QuestionType::Text->value,
                    'points' => 1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions.0.expected_answer');
    }

    /** У вопроса с выбором эталон не нужен, а два варианта — обязательны. */
    public function test_a_choice_question_still_needs_options(): void
    {
        $course = Course::factory()->withLessons(1)->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $course->lessons()->first()), [
                'title' => 'Проверка',
                'passing_score' => 100,
                'questions' => [[
                    'text' => 'Сколько будет два плюс два?',
                    'type' => QuestionType::Single->value,
                    'points' => 1,
                    'options' => [['text' => 'Четыре', 'is_correct' => true]],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions.0.options');
    }

    /* ---------- Проверяет ИИ ---------- */

    public function test_an_answer_in_other_words_is_accepted(): void
    {
        $this->fakeEmbeddings();

        [$document, $quiz] = $this->documentWithWrittenQuestion(
            'Деньги заморожены в запасах и в дебиторке',
        );
        $question = $quiz->questions()->sole();
        $reader = $this->learner();

        $this->actingAs($reader)
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                // Те же слова в другом порядке: смысл один, формулировка другая.
                'answers' => [$question->id => 'В дебиторке и в запасах заморожены деньги'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.score', 100)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.is_acknowledged', true);

        $attempt = QuizAttempt::query()->sole();

        $this->assertSame(1, $attempt->scores[$question->id]['points']);
        $this->assertSame('meaning', $attempt->scores[$question->id]['measured_by']);
        $this->assertGreaterThanOrEqual(
            (float) config('ai.answer_similarity_floor'),
            $attempt->scores[$question->id]['similarity'],
        );
    }

    public function test_an_answer_about_something_else_is_refused(): void
    {
        $this->fakeEmbeddings();

        [$document, $quiz] = $this->documentWithWrittenQuestion(
            'Деньги заморожены в запасах и в дебиторке',
        );
        $question = $quiz->questions()->sole();

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => 'Надо уволить кладовщика и сократить рекламу'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.is_acknowledged', false);
    }

    public function test_an_empty_answer_scores_nothing(): void
    {
        $this->fakeEmbeddings();

        [$document, $quiz] = $this->documentWithWrittenQuestion('Что-нибудь осмысленное');
        $question = $quiz->questions()->sole();

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => '   '],
            ])
            ->assertCreated()
            ->assertJsonPath('data.score', 0);
    }

    /**
     * Провайдер эмбеддингов может быть не настроен или лежать: тогда меряем
     * пересечением слов и говорим об этом прямо — шкалы у мерок разные.
     */
    public function test_without_embeddings_the_words_are_compared_instead(): void
    {
        config(['ai.api_key' => null, 'ai.embedding_model' => null]);

        [$document, $quiz] = $this->documentWithWrittenQuestion(
            'Деньги заморожены в запасах и в дебиторке',
        );
        $question = $quiz->questions()->sole();

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => 'Деньги заморожены в дебиторке и в запасах'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', true);

        $this->assertSame('words', QuizAttempt::query()->sole()->scores[$question->id]['measured_by']);
    }

    /* ---------- Что видно в разборе ---------- */

    public function test_the_review_shows_the_similarity_but_not_the_reference(): void
    {
        $this->fakeEmbeddings();

        [$document, $quiz] = $this->documentWithWrittenQuestion('Секретный эталон про запасы');
        $question = $quiz->questions()->sole();

        $review = $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => 'Мой ответ про склад'],
            ])
            ->assertCreated()
            ->json('data.review.questions.0');

        $this->assertSame('Мой ответ про склад', $review['answer']);
        $this->assertNotNull($review['similarity']);
        $this->assertSame('meaning', $review['measured_by']);
        // Эталон — тот же ключ: пока попытки не кончились, он закрыт.
        $this->assertNull($review['expected_answer']);
    }

    public function test_the_reference_opens_when_the_attempts_run_out(): void
    {
        $this->fakeEmbeddings();

        [$document, $quiz] = $this->documentWithWrittenQuestion('Секретный эталон про запасы');
        $quiz->update(['max_attempts' => 1]);
        $question = $quiz->questions()->sole();

        $review = $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => 'Совсем не то'],
            ])
            ->assertCreated()
            ->json('data.review.questions.0');

        $this->assertSame('Секретный эталон про запасы', $review['expected_answer']);
    }

    /** Сотруднику, который сидит над тестом, эталон не отдают вместе с вопросами. */
    public function test_the_reference_is_never_sent_with_the_questions(): void
    {
        [$document] = $this->documentWithWrittenQuestion('Секретный эталон про запасы');

        $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.show', $document))
            ->assertOk()
            ->assertJsonPath('data.quiz.questions.0.expected_answer', null);
    }

    /* ---------- Разбор для автора ---------- */

    public function test_the_author_sees_the_average_similarity(): void
    {
        $this->fakeEmbeddings();

        [$document, $quiz] = $this->documentWithWrittenQuestion('Деньги в запасах и дебиторке');
        $question = $quiz->questions()->sole();

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => 'Деньги в дебиторке и запасах'],
            ])
            ->assertCreated();

        $statistics = $this->actingAs($this->author())
            ->getJson(route('lms.regulations.quiz.statistics', $document))
            ->assertOk()
            ->assertJsonPath('data.questions.0.answered', 1)
            ->assertJsonPath('data.questions.0.correct', 1)
            // Вариантов у письменного вопроса нет — вместо них средняя
            // схожесть: по ней автор и видит, не узок ли его эталон.
            ->assertJsonCount(0, 'data.questions.0.options')
            ->json('data.questions.0');

        $this->assertNotNull($statistics['average_similarity']);
    }
}
