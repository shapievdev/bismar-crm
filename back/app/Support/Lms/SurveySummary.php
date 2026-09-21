<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use Illuminate\Support\Collection;

/**
 * Что опрос показывает тому, кто ведёт материал.
 *
 * Сводка, а не выгрузка ответов по одному: опрос спрашивают у всей компании, и
 * читать его построчно — работа, которую никто не сделает. По выбору — сколько
 * за что, по шкале — распределение и среднее, по письменному — сами ответы: их
 * как раз читают, в них и лежит то, ради чего опрос заводили.
 *
 * Анонимность соблюдается тем, что соблюдать её здесь нечем: в строке ответа у
 * анонимного опроса человека нет вовсе (см. SurveyResponse), и подставить его
 * сюда неоткуда. Сводка лишь говорит об этом прямо — чтобы читающий понимал,
 * почему у ответов нет имён.
 *
 * Считается в приложении, а не в базе. Ответы лежат снимком в json, и каждый вид
 * вопроса читается из него по-своему: в SQL это были бы три разных запроса с
 * разбором json, по запросу на вид. Опрос при материале — это сотни строк в
 * худшем случае, и проход по ним стоит меньше, чем такой запрос.
 */
final readonly class SurveySummary
{
    /**
     * @return array<string, mixed>
     */
    public function of(Survey $survey): array
    {
        $survey->loadMissing('questions.options');

        $responses = $survey->responses()
            // Именами строки обрастают здесь же: у неанонимного опроса они нужны
            // почти всегда, а второй запрос за теми же людьми ничего не убрал бы.
            ->with('user:id,last_name,first_name,middle_name')
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();

        return [
            'is_anonymous' => $survey->is_anonymous,
            'is_required' => $survey->is_required,
            'closes_at' => $survey->closes_at?->toIso8601String(),
            'is_open' => $survey->isOpen(),

            // Прошедших считаем по отметкам, а не по строкам ответов: у
            // анонимного опроса это единственное, что вообще знает о людях.
            'answered' => $survey->completions()->count(),

            'questions' => $survey->questions
                ->map(fn (SurveyQuestion $question): array => $this->question($question, $responses))
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, SurveyResponse>  $responses
     * @return array<string, mixed>
     */
    private function question(SurveyQuestion $question, Collection $responses): array
    {
        $answers = $responses
            ->map(fn (SurveyResponse $response): array => [
                'answer' => $response->answerTo((int) $question->getKey()),
                // Кто ответил — или null у анонимного опроса и у того, чью
                // запись удалили. Экран различать эти два случая не должен:
                // ответ есть, человека нет.
                'person' => $response->user?->name,
            ])
            // Пропущенный вопрос в сводке не участвует: «не ответил» — это не
            // ответ, и считать его наравне с прочими значило бы занижать доли.
            ->filter(static fn (array $row): bool => $row['answer'] !== [])
            ->values();

        $summary = [
            'id' => $question->getKey(),
            'text' => $question->text,
            'type' => $question->type->value,
            'is_required' => $question->is_required,
            'answered' => $answers->count(),
        ];

        if ($question->type->isChoice()) {
            return [...$summary, ...$this->choice($question, $answers)];
        }

        if ($question->type->isScale()) {
            return [...$summary, ...$this->scale($question, $answers)];
        }

        return [...$summary, ...$this->written($answers)];
    }

    /**
     * Сколько за какой вариант — и что написали в «своём».
     *
     * Доля считается от числа ответивших на вопрос, а не от числа прошедших
     * опрос: у вопроса, который можно пропустить, второе всегда больше, и доли
     * не сошлись бы в сто процентов ни при каком ответе.
     *
     * @param  Collection<int, array{answer: array<string, mixed>, person: ?string}>  $answers
     * @return array<string, mixed>
     */
    private function choice(SurveyQuestion $question, Collection $answers): array
    {
        $chosen = $answers
            ->flatMap(static fn (array $row): array => array_map(
                intval(...),
                is_array($row['answer']['options'] ?? null) ? $row['answer']['options'] : [],
            ))
            ->countBy()
            ->all();

        $total = max($answers->count(), 1);

        return [
            'options' => $question->options
                ->map(function ($option) use ($chosen, $total): array {
                    $count = (int) ($chosen[(int) $option->getKey()] ?? 0);

                    return [
                        'id' => $option->getKey(),
                        'text' => $option->text,
                        'count' => $count,
                        'share' => (int) round($count / $total * 100),
                    ];
                })
                ->all(),

            // Свои варианты — списком, как письменные ответы: их читают.
            'other' => $answers
                ->filter(static fn (array $row): bool => ($row['answer']['other'] ?? null) !== null)
                ->map(static fn (array $row): array => [
                    'text' => (string) $row['answer']['other'],
                    'person' => $row['person'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Распределение по делениям и среднее.
     *
     * Деления берутся у вопроса, а не у ответов: у шкалы, которую никто не
     * оценил в единицу, единица всё равно должна стоять в сводке нулём — иначе
     * распределение врёт о том, чего не выбирали.
     *
     * @param  Collection<int, array{answer: array<string, mixed>, person: ?string}>  $answers
     * @return array<string, mixed>
     */
    private function scale(SurveyQuestion $question, Collection $answers): array
    {
        $values = $answers
            ->map(static fn (array $row): ?int => is_numeric($row['answer']['scale'] ?? null)
                ? (int) $row['answer']['scale']
                : null)
            ->filter(static fn (?int $value): bool => $value !== null)
            ->values();

        $counts = $values->countBy()->all();

        return [
            'scale' => [
                'min_label' => $question->scale_min_label,
                'max_label' => $question->scale_max_label,
                'steps' => array_map(
                    static fn (int $step): array => [
                        'value' => $step,
                        'count' => (int) ($counts[$step] ?? 0),
                    ],
                    $question->scaleSteps(),
                ),
                // Среднее с одним знаком: «4,2» о шкале говорит, «4,17» — нет.
                'average' => $values->isEmpty() ? null : round((float) $values->avg(), 1),
            ],
        ];
    }

    /**
     * Написанное — как есть и целиком.
     *
     * Ни сокращений, ни «показать ещё»: ради этих ответов опрос и заводят, а
     * подрезанное на середине предложение меняет смысл.
     *
     * @param  Collection<int, array{answer: array<string, mixed>, person: ?string}>  $answers
     * @return array<string, mixed>
     */
    private function written(Collection $answers): array
    {
        return [
            'texts' => $answers
                ->filter(static fn (array $row): bool => ($row['answer']['text'] ?? null) !== null)
                ->map(static fn (array $row): array => [
                    'text' => (string) $row['answer']['text'],
                    'person' => $row['person'],
                ])
                ->values()
                ->all(),
        ];
    }
}
