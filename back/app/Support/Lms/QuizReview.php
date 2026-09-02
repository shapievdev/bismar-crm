<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;

/**
 * Разбор попытки: что человек выбрал и где ошибся.
 *
 * Без него тест только преграда. Сотрудник получает «68%, не сдано» и идёт
 * проходить заново с тем же знанием, с каким пришёл, — а пересдача без разбора
 * учит разве что перебирать варианты.
 *
 * Разбор строится по нынешнему тесту, а ответы хранятся снимком выбранных
 * вариантов. Вопрос, добавленный после попытки, поэтому числится
 * неотвеченным — это правда: его и не показывали.
 */
final readonly class QuizReview
{
    /**
     * Разбор попытки для того, кто её проходил.
     *
     * @return array<string, mixed>
     */
    public function of(QuizAttempt $attempt, User $learner): array
    {
        return $this->build($attempt, $this->revealsKey($attempt->quiz, $learner));
    }

    /**
     * Разбор чужой попытки — тому, кто ведёт материал.
     *
     * Ключ здесь открыт всегда, и прятать его не от кого: верные ответы автор
     * сам и написал, а видит их в редакторе теста. Смысл разбора у него
     * обратный ученическому — не «где я ошибся», а «где урок не научил», и без
     * верного ответа рядом с отправленным этого не прочесть.
     *
     * @return array<string, mixed>
     */
    public function forAuthor(QuizAttempt $attempt): array
    {
        return $this->build($attempt, revealsKey: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(QuizAttempt $attempt, bool $revealsKey): array
    {
        $quiz = $attempt->quiz;

        $quiz->loadMissing('questions.options');

        /** @var array<int|string, list<int>> $submitted */
        $submitted = $attempt->answers ?? [];

        /** @var array<int|string, array<string, mixed>> $scores */
        $scores = $attempt->scores ?? [];

        $questions = $quiz->questions->map(function (QuizQuestion $question) use ($submitted, $scores, $revealsKey): array {
            $given = $submitted[$question->getKey()] ?? $submitted[(string) $question->getKey()] ?? null;
            $score = $scores[$question->getKey()] ?? $scores[(string) $question->getKey()] ?? [];

            return match (true) {
                $question->type->isWritten() => $this->writtenQuestion(
                    $question,
                    is_string($given) ? $given : null,
                    $score,
                    $revealsKey,
                ),
                $question->type->isTable() => $this->tableQuestion(
                    $question,
                    is_array($given) ? $given : [],
                    $score,
                    $revealsKey,
                ),
                default => $this->question(
                    $question,
                    array_map(intval(...), is_array($given) ? $given : []),
                    $revealsKey,
                ),
            };
        })->values()->all();

        return [
            // Раскрыт ли верный ответ. Экран по этому решает, показывать
            // разбор целиком или только «здесь неверно».
            'reveals_key' => $revealsKey,
            'questions' => $questions,
        ];
    }

    /**
     * Показывать ли верные ответы.
     *
     * Пока попытки остались, — нет. Иначе тест с неограниченным числом попыток
     * проходится так: отправить пустое, посмотреть ключ, отправить его обратно.
     * Что именно отвечено неверно, человек видит в любом случае: это говорит,
     * куда вернуться в материале, и ничего не выдаёт.
     *
     * Сдача ключа больше не открывает (решение пользователя 2026-09-02).
     * Сдавшему он и не нужен: планка — все верные ответы, то есть ключ и есть
     * то, что он сам отправил. А открытый после сдачи, он превращается в
     * готовые ответы, которые первый же сдавший пересказывает остальным.
     *
     * Кончились попытки — скрывать больше нечего: пересдать человек не может,
     * а узнать, как было правильно, ему нужнее всего.
     */
    private function revealsKey(Quiz $quiz, User $learner): bool
    {
        return ! $quiz->hasAttemptsLeft($learner);
    }

    /**
     * Письменный вопрос в разборе.
     *
     * Своё написанное человек видит всегда — оно и так его, — а вместе с ним
     * схожесть с эталоном и то, чем она измерена: «не зачтено» без числа
     * выглядит произволом. Сам эталон открывается по тем же правилам, что и
     * ключ у выбора: иначе первый же сдавший унёс бы готовый ответ.
     *
     * @param  array<string, mixed>  $score
     * @return array<string, mixed>
     */
    private function writtenQuestion(
        QuizQuestion $question,
        ?string $given,
        array $score,
        bool $revealsKey,
    ): array {
        $written = trim((string) $given);

        return [
            'id' => $question->getKey(),
            'text' => $question->text,
            'type' => $question->type->value,
            'points' => $question->points,

            'is_correct' => ($score['points'] ?? 0) > 0,
            'is_answered' => $written !== '',
            'selected_option_ids' => [],
            'options' => [],

            // Что человек написал и как это оценено.
            'answer' => $written === '' ? null : $written,
            'similarity' => $score['similarity'] ?? null,
            'threshold' => $score['threshold'] ?? null,
            'measured_by' => $score['measured_by'] ?? null,
            'expected_answer' => $revealsKey ? $question->expected_answer : null,
        ];
    }

    /**
     * Таблица в разборе.
     *
     * Показывается как заполнена — она и есть работа человека. Рядом стоит счёт
     * ячеек: «заполнено 55 из 60» объясняет отказ точнее любых слов, а
     * разошедшиеся с эталоном ячейки названы по координатам — иначе человек
     * перепроверял бы шестьдесят ячеек вслепую.
     *
     * Сами ожидаемые значения открываются по тем же правилам, что и ключ у
     * выбора: пока попытки есть, они закрыты.
     *
     * @param  array<int|string, mixed>  $given
     * @param  array<string, mixed>  $score
     * @return array<string, mixed>
     */
    private function tableQuestion(
        QuizQuestion $question,
        array $given,
        array $score,
        bool $revealsKey,
    ): array {
        /** @var list<list<string>> $rows */
        $rows = array_values(array_map(
            static fn (array $row): array => array_values(array_map(strval(...), $row)),
            array_filter($given, is_array(...)),
        ));

        return [
            'id' => $question->getKey(),
            'text' => $question->text,
            'type' => $question->type->value,
            'points' => $question->points,

            'is_correct' => ($score['points'] ?? 0) > 0,
            'is_answered' => $rows !== [],
            'selected_option_ids' => [],
            'options' => [],

            'table' => $this->tableShape($question, $revealsKey),
            'table_answer' => $rows,
            'filled_cells' => $score['filled_cells'] ?? null,
            'required_cells' => $score['required_cells'] ?? null,

            // Сверяемые ячейки: сколько их, сколько совпало и какие нет.
            'checked_cells' => $score['checked_cells'] ?? null,
            'correct_cells' => $score['correct_cells'] ?? null,
            'wrong_cells' => $score['wrong'] ?? [],
        ];
    }

    /**
     * Форма таблицы для разбора: ожидаемые значения ячеек — ключ, и пока
     * попытки есть, они из неё вычищаются.
     *
     * @return array<string, mixed>|null
     */
    private function tableShape(QuizQuestion $question, bool $revealsKey): ?array
    {
        /** @var array<string, mixed>|null $table */
        $table = $question->table_definition;

        if ($table === null || $revealsKey) {
            return $table;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];

        $table['rows'] = array_map(
            static fn (array $row): array => ['label' => $row['label'] ?? ''],
            $rows,
        );

        return $table;
    }

    /**
     * @param  list<int>  $selected
     * @return array<string, mixed>
     */
    private function question(QuizQuestion $question, array $selected, bool $revealsKey): array
    {
        $correct = $question->correctOptionIds()->map(intval(...))->sort()->values()->all();
        $chosen = collect($selected)->unique()->sort()->values()->all();

        return [
            'id' => $question->getKey(),
            'text' => $question->text,
            'type' => $question->type->value,
            'points' => $question->points,

            // Считается той же меркой, что и балл: вариант в вариант, без
            // частичного зачёта, — иначе разбор расходился бы с оценкой.
            'is_correct' => $correct === $chosen && $correct !== [],
            'is_answered' => $chosen !== [],
            'selected_option_ids' => $chosen,

            'options' => $question->options->map(fn ($option): array => [
                'id' => $option->id,
                'text' => $option->text,
                'is_chosen' => in_array((int) $option->id, $chosen, strict: true),
                // Ключ уходит только вместе с правом его видеть: собрать его
                // из разбора должно быть так же нельзя, как из самого теста.
                'is_correct' => $revealsKey ? (bool) $option->is_correct : null,
            ])->values()->all(),
        ];
    }
}
