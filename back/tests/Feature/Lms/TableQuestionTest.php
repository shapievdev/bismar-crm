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
use App\Support\Lms\QuestionTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Вопрос-таблица: месяцы, недели, статьи расходов.
 *
 * Верны ли числа в таблице, приложение знать не может — это работа, которую
 * читает человек. Поэтому зачёт по заполненности: заготовленные строки должны
 * быть заполнены целиком, а добавленная — либо целиком, либо пусто.
 */
final class TableQuestionTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    private function author(): User
    {
        return $this->userWith(Permission::ViewCourses, Permission::UpdateCourses);
    }

    /**
     * Таблица «13 недель» из настоящего бланка: подписи у строк есть, их
     * сотрудник не правит.
     *
     * @return array<string, mixed>
     */
    private function weeks(int $weeks = 3): array
    {
        return [
            'row_label_title' => 'Неделя',
            'can_add_rows' => false,
            'columns' => [
                ['title' => 'Поступления', 'kind' => QuestionTable::TEXT],
                ['title' => 'Платежи', 'kind' => QuestionTable::TEXT],
            ],
            'rows' => array_map(
                static fn (int $number): array => ['label' => "Неделя {$number}"],
                range(1, $weeks),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $table
     * @return array{Regulation, Quiz}
     */
    private function documentWithTable(array $table): array
    {
        $document = Regulation::factory()->published()->create();

        $quiz = Quiz::factory()->create(['quizzable_type' => 'regulation', 'quizzable_id' => $document->id]);
        $quiz->questions()->create([
            'text' => 'Прогноз денег на 13 недель',
            'type' => QuestionType::Table,
            'table_definition' => QuestionTable::normalise($table),
            'points' => 3,
            'position' => 0,
        ]);

        return [$document, $quiz->refresh()];
    }

    /* ---------- Автор собирает таблицу ---------- */

    public function test_an_author_builds_a_table_with_a_select_column(): void
    {
        $course = Course::factory()->withLessons(1)->create();

        $response = $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $course->lessons()->first()), [
                'title' => 'Постоянные расходы',
                'passing_score' => 100,
                'questions' => [[
                    'text' => 'Разнесите расходы по типу',
                    'type' => QuestionType::Table->value,
                    'points' => 2,
                    'table' => [
                        'row_label_title' => 'Группа расходов',
                        'can_add_rows' => true,
                        'columns' => [
                            ['title' => 'Сумма в месяц', 'kind' => 'text'],
                            [
                                'title' => 'Тип',
                                'kind' => 'select',
                                'options' => ['Постоянные', 'Переменные'],
                            ],
                        ],
                        'rows' => [['label' => 'Аренда'], ['label' => '']],
                    ],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.questions.0.type', QuestionType::Table->value)
            ->assertJsonPath('data.questions.0.table.row_label_title', 'Группа расходов')
            ->assertJsonPath('data.questions.0.table.can_add_rows', true)
            ->assertJsonCount(0, 'data.questions.0.options');

        $table = $response->json('data.questions.0.table');

        $this->assertSame('select', $table['columns'][1]['kind']);
        $this->assertSame(['Постоянные', 'Переменные'], $table['columns'][1]['options']);
        // Пустая подпись — строка, название которой вписывает сам сотрудник.
        $this->assertSame('', $table['rows'][1]['label']);
    }

    public function test_a_table_without_columns_is_refused(): void
    {
        $course = Course::factory()->withLessons(1)->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $course->lessons()->first()), [
                'title' => 'Пустая таблица',
                'passing_score' => 100,
                'questions' => [[
                    'text' => 'Заполните',
                    'type' => QuestionType::Table->value,
                    'points' => 1,
                    'table' => ['rows' => [['label' => 'Строка']], 'columns' => []],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions.0.table.columns');
    }

    public function test_a_select_column_without_options_is_refused(): void
    {
        $course = Course::factory()->withLessons(1)->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $course->lessons()->first()), [
                'title' => 'Таблица',
                'passing_score' => 100,
                'questions' => [[
                    'text' => 'Заполните',
                    'type' => QuestionType::Table->value,
                    'points' => 1,
                    'table' => [
                        'columns' => [['title' => 'Тип', 'kind' => 'select', 'options' => []]],
                        'rows' => [['label' => 'Строка']],
                    ],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions.0.table.columns.0.options');
    }

    /** Форма таблицы — не ключ: без неё заполнять нечего, поэтому едет всем. */
    public function test_the_shape_of_the_table_reaches_the_learner(): void
    {
        [$document] = $this->documentWithTable($this->weeks());

        $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.show', $document))
            ->assertOk()
            ->assertJsonPath('data.quiz.questions.0.table.row_label_title', 'Неделя')
            ->assertJsonCount(3, 'data.quiz.questions.0.table.rows')
            ->assertJsonCount(2, 'data.quiz.questions.0.table.columns');
    }

    /* ---------- Зачёт по заполненности ---------- */

    public function test_a_filled_table_is_accepted(): void
    {
        [$document, $quiz] = $this->documentWithTable($this->weeks());
        $question = $quiz->questions()->sole();

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => [
                    ['120000', '90000'],
                    ['80000', '150000'],
                    ['200000', '110000'],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.score', 100)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.is_acknowledged', true)
            ->assertJsonPath('data.review.questions.0.filled_cells', 6)
            ->assertJsonPath('data.review.questions.0.required_cells', 6);
    }

    public function test_a_half_filled_table_is_refused(): void
    {
        [$document, $quiz] = $this->documentWithTable($this->weeks());
        $question = $quiz->questions()->sole();

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => [
                    ['120000', '90000'],
                    ['80000', ''],
                    ['', ''],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.passed', false)
            // Счёт ячеек объясняет отказ точнее любых слов.
            ->assertJsonPath('data.review.questions.0.filled_cells', 3)
            ->assertJsonPath('data.review.questions.0.required_cells', 6);
    }

    public function test_missing_rows_count_as_empty(): void
    {
        [$document, $quiz] = $this->documentWithTable($this->weeks());
        $question = $quiz->questions()->sole();

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                // Прислали одну строку из трёх: остальные пусты.
                'answers' => [$question->id => [['120000', '90000']]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', false);
    }

    /**
     * Добавленная строка — либо целиком, либо пусто: полупустая появилась
     * случайно, и держать из-за неё всю сдачу незачем.
     */
    public function test_an_added_row_is_all_or_nothing(): void
    {
        [$document, $quiz] = $this->documentWithTable([
            'row_label_title' => 'Группа расходов',
            'can_add_rows' => true,
            'columns' => [['title' => 'Сумма', 'kind' => QuestionTable::TEXT]],
            'rows' => [['label' => 'Аренда']],
        ]);
        $question = $quiz->questions()->sole();

        // Ведущая ячейка подписанной строки приходит с самой подписью — так
        // ширина строки одна на всю таблицу.
        $filled = [['Аренда', '50000']];

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => [...$filled, ['', '']]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', true);

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => [...$filled, ['Логистика', '']]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', false);
    }

    /* ---------- Эталон по ячейкам ---------- */

    /**
     * Где правильный ответ есть — его сверяют. В прогнозе на 13 недель таких
     * ячеек нет вовсе: это данные компании сотрудника.
     */
    public function test_a_cell_with_a_reference_is_checked(): void
    {
        [$document, $quiz] = $this->documentWithTable([
            'row_label_title' => 'Группа расходов',
            'can_add_rows' => false,
            'columns' => [
                ['title' => 'Сумма', 'kind' => QuestionTable::TEXT],
                ['title' => 'Тип', 'kind' => QuestionTable::SELECT, 'options' => ['Постоянные', 'Переменные']],
            ],
            'rows' => [
                // Сумму не сверяем — она своя у каждой компании; тип сверяем.
                ['label' => 'Аренда', 'expected' => ['', 'Постоянные']],
                ['label' => 'Закупка товара', 'expected' => ['', 'Переменные']],
            ],
        ]);
        $question = $quiz->questions()->sole();
        $reader = $this->learner();

        $this->actingAs($reader)
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => [
                    ['150000', 'Постоянные'],
                    ['900000', 'Постоянные'],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.review.questions.0.checked_cells', 2)
            ->assertJsonPath('data.review.questions.0.correct_cells', 1)
            // Разошедшаяся ячейка названа по координатам: иначе человек
            // перепроверял бы всю таблицу вслепую.
            ->assertJsonPath('data.review.questions.0.wrong_cells', [['row' => 1, 'cell' => 1]]);

        $this->actingAs($reader)
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => [
                    ['150000', 'Постоянные'],
                    ['900000', 'Переменные'],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.review.questions.0.wrong_cells', []);
    }

    /**
     * Числа сравниваются как числа: придираться к пробелам между разрядами
     * значит браковать верный ответ.
     */
    public function test_numbers_are_compared_as_numbers(): void
    {
        [$document, $quiz] = $this->documentWithTable([
            'row_label_title' => 'Показатель',
            'can_add_rows' => false,
            'columns' => [['title' => 'Сумма', 'kind' => QuestionTable::TEXT]],
            'rows' => [['label' => 'Итого', 'expected' => ['1200000']]],
        ]);
        $question = $quiz->questions()->sole();

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => [['1 200 000']]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', true);
    }

    /** Текст сверяется без учёта регистра и знаков. */
    public function test_text_is_compared_loosely(): void
    {
        [$document, $quiz] = $this->documentWithTable([
            'row_label_title' => 'Показатель',
            'can_add_rows' => false,
            'columns' => [['title' => 'Ответ', 'kind' => QuestionTable::TEXT]],
            'rows' => [['label' => 'Диагноз', 'expected' => ['Ликвидность']]],
        ]);
        $question = $quiz->questions()->sole();

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => [['  ликвидность.  ']]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', true);
    }

    /**
     * Эталон доезжает до базы через тот же запрос, которым автор сохраняет
     * тест: без правила проверки он молча отбрасывался бы разбором присланного.
     */
    public function test_the_expected_cells_survive_the_save(): void
    {
        $course = Course::factory()->withLessons(1)->create();

        $this->actingAs($this->author())
            ->putJson(route('lms.quiz.save', $course->lessons()->first()), [
                'title' => 'Расходы',
                'passing_score' => 100,
                'questions' => [[
                    'text' => 'Разнесите расходы по типу',
                    'type' => QuestionType::Table->value,
                    'points' => 2,
                    'table' => [
                        'row_label_title' => 'Группа расходов',
                        'columns' => [
                            ['title' => 'Сумма', 'kind' => 'text'],
                            ['title' => 'Тип', 'kind' => 'select', 'options' => ['Постоянные', 'Переменные']],
                        ],
                        'rows' => [
                            // Сумма своя у каждой компании, тип — знание.
                            ['label' => 'Аренда', 'expected' => ['', 'Постоянные']],
                            ['label' => 'Закупка товара', 'expected' => ['', 'Переменные']],
                        ],
                    ],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.questions.0.table.rows.0.expected', ['', 'Постоянные'])
            ->assertJsonPath('data.questions.0.table.rows.1.expected', ['', 'Переменные']);
    }

    /** Эталон ячеек — тот же ключ: сотруднику он не уходит. */
    public function test_the_expected_cells_are_never_sent_to_the_learner(): void
    {
        [$document] = $this->documentWithTable([
            'row_label_title' => 'Группа',
            'can_add_rows' => false,
            'columns' => [['title' => 'Тип', 'kind' => QuestionTable::TEXT]],
            'rows' => [['label' => 'Аренда', 'expected' => ['Постоянные']]],
        ]);

        $row = $this->actingAs($this->learner())
            ->getJson(route('lms.regulations.show', $document))
            ->assertOk()
            ->json('data.quiz.questions.0.table.rows.0');

        $this->assertSame(['label' => 'Аренда'], $row);

        // Автору он виден: это его же ключ.
        $this->actingAs($this->author())
            ->getJson(route('lms.regulations.show', $document))
            ->assertOk()
            ->assertJsonPath('data.quiz.questions.0.table.rows.0.expected', ['Постоянные']);
    }

    /* ---------- Что видно потом ---------- */

    public function test_the_review_keeps_what_was_filled_in(): void
    {
        [$document, $quiz] = $this->documentWithTable($this->weeks(weeks: 1));
        $question = $quiz->questions()->sole();

        $review = $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => [['120000', '90000']]],
            ])
            ->assertCreated()
            ->json('data.review.questions.0');

        $this->assertSame([['120000', '90000']], $review['table_answer']);
        $this->assertSame('Неделя', $review['table']['row_label_title']);

        $this->assertSame(
            [['120000', '90000']],
            QuizAttempt::query()->sole()->answers[$question->id],
        );
    }

    public function test_the_author_sees_how_many_filled_the_table(): void
    {
        [$document, $quiz] = $this->documentWithTable($this->weeks(weeks: 1));
        $question = $quiz->questions()->sole();

        $this->actingAs($this->learner())
            ->postJson(route('lms.regulations.quiz.submit', $document), [
                'answers' => [$question->id => [['120000', '']]],
            ])
            ->assertCreated();

        $this->actingAs($this->author())
            ->getJson(route('lms.regulations.quiz.statistics', $document))
            ->assertOk()
            // Тронул таблицу, но не дозаполнил: отвечавший один, зачтённых нет.
            ->assertJsonPath('data.questions.0.answered', 1)
            ->assertJsonPath('data.questions.0.correct', 0)
            ->assertJsonCount(0, 'data.questions.0.options');
    }
}
