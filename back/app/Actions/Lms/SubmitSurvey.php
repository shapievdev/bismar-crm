<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\SurveyQuestionType;
use App\Exceptions\ConflictException;
use App\Models\Enrollment;
use App\Models\Survey;
use App\Models\SurveyCompletion;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Прохождение опроса — один раз и насовсем.
 *
 * Повторных попыток у опроса нет (решение пользователя 2026-09-21), и держится
 * это не проверкой в коде, а уникальным ключом на паре «опрос — человек»:
 * проверка пропустила бы два браузера, нажавших «отправить» одновременно, а ключ
 * не пропускает. Отказ по ключу и отказ по проверке человек читает одинаково —
 * «вы уже проходили этот опрос», — и различать их незачем.
 *
 * Анонимность исполняется здесь же, и единственным способом, каким её можно
 * исполнить: у анонимного опроса в строке с ответами не остаётся человека вовсе.
 * Отметка о прохождении при этом пишется всегда — иначе обязательный опрос
 * нечем закрыть, и человека спрашивали бы снова и снова.
 *
 * Присланное сверяется с самим опросом, а не только с правилами формата: вопрос
 * из чужого опроса, вариант из другого вопроса и семёрка на пятибалльной шкале
 * отбрасываются. Форма всего этого не пришлёт — но запрос приходит не только из
 * формы.
 */
final readonly class SubmitSurvey
{
    public function __construct(private CreditMaterial $credit) {}

    /**
     * @param  array<int|string, array{options?: array<int, int|string>, other?: ?string, scale?: int|string|null, text?: ?string}>  $answers
     *
     * @throws ConflictException
     * @throws ValidationException
     */
    public function handle(
        Survey $survey,
        User $respondent,
        array $answers,
        ?Enrollment $enrollment = null,
    ): SurveyResponse {
        if (! $survey->isOpen()) {
            throw new ConflictException('Опрос закрыт: срок приёма ответов вышел.');
        }

        if ($survey->isAnsweredBy($respondent)) {
            throw new ConflictException('Вы уже проходили этот опрос — второй раз его не проходят.');
        }

        $survey->loadMissing('questions.options');

        $prepared = $this->prepared($survey, $answers);

        try {
            $response = DB::transaction(function () use ($survey, $respondent, $prepared): SurveyResponse {
                // Отметка первой: она же и есть замок от второго прохождения, и
                // упасть на ней лучше до того, как записаны ответы.
                SurveyCompletion::create([
                    'survey_id' => $survey->getKey(),
                    'user_id' => $respondent->getKey(),
                    'completed_at' => now(),
                ]);

                return SurveyResponse::create([
                    'survey_id' => $survey->getKey(),
                    // Анонимный опрос не запоминает, кто ответил, — см. заголовок.
                    'user_id' => $survey->is_anonymous ? null : $respondent->getKey(),
                    'answers' => $prepared,
                    'submitted_at' => now(),
                ]);
            });
        } catch (QueryException $failure) {
            // Замок сработал: второй запрос успел прийти, пока шёл первый.
            // Человеку это тот же ответ, что и проверка выше.
            if ($this->isDuplicate($failure)) {
                throw new ConflictException('Вы уже проходили этот опрос — второй раз его не проходят.');
            }

            throw $failure;
        }

        /*
         * Отправленный опрос мог быть последним, чего материал ждал от человека:
         * тогда материал зачитывается сразу, а не после того, как он сам найдёт
         * кнопку. Решает это одно место на всё приложение — см. CreditMaterial.
         */
        $this->credit->handle($survey->surveyable, $respondent, $enrollment);

        return $response;
    }

    /**
     * Нарушен ли уникальный ключ.
     *
     * По коду состояния Postgres (23505), а не по тексту сообщения: текст
     * приходит на языке сервера базы и меняется от версии к версии.
     */
    private function isDuplicate(QueryException $failure): bool
    {
        return ($failure->errorInfo[0] ?? null) === '23505';
    }

    /**
     * Ответы, сверенные с самим опросом и разложенные по номерам вопросов.
     *
     * @param  array<int|string, array{options?: array<int, int|string>, other?: ?string, scale?: int|string|null, text?: ?string}>  $answers
     * @return array<string, array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function prepared(Survey $survey, array $answers): array
    {
        $prepared = [];

        foreach ($survey->questions as $question) {
            $given = $answers[$question->getKey()] ?? $answers[(string) $question->getKey()] ?? [];
            $answer = $this->answer($question, is_array($given) ? $given : []);

            if ($answer === null) {
                if ($question->is_required) {
                    throw ValidationException::withMessages([
                        'answers' => sprintf('Ответьте на вопрос «%s» — он обязателен.', $question->text),
                    ]);
                }

                continue;
            }

            $prepared[(string) $question->getKey()] = $answer;
        }

        return $prepared;
    }

    /**
     * Ответ на один вопрос — или null, если человек его пропустил.
     *
     * Пропуск и пустой ответ здесь одно и то же: отмеченное ничто, выбранное
     * ничто и пробелы в поле — всё это «не ответил», и хранить их как ответ
     * значило бы считать их в сводке.
     *
     * @param  array{options?: array<int, int|string>, other?: ?string, scale?: int|string|null, text?: ?string}  $given
     * @return array<string, mixed>|null
     */
    private function answer(SurveyQuestion $question, array $given): ?array
    {
        if ($question->type->isChoice()) {
            return $this->choice($question, $given);
        }

        if ($question->type->isScale()) {
            $steps = $question->scaleSteps();
            $scale = is_numeric($given['scale'] ?? null) ? (int) $given['scale'] : null;

            // Делением шкалы может быть только её деление: семёрка на шкале до
            // пяти — это не ответ, а подобранный запрос.
            return $scale !== null && in_array($scale, $steps, strict: true)
                ? ['scale' => $scale]
                : null;
        }

        $text = trim((string) ($given['text'] ?? ''));

        return $text === '' ? null : ['text' => $text];
    }

    /**
     * Отмеченные варианты — только свои, и только столько, сколько вид вопроса
     * разрешает.
     *
     * @param  array{options?: array<int, int|string>, other?: ?string}  $given
     * @return array<string, mixed>|null
     */
    private function choice(SurveyQuestion $question, array $given): ?array
    {
        $own = $question->options->pluck('id')->map(intval(...))->all();

        $chosen = array_values(array_unique(array_filter(
            array_map(intval(...), array_values($given['options'] ?? [])),
            static fn (int $id): bool => in_array($id, $own, strict: true),
        )));

        // Один вариант — значит один: присланный список из двух в вопросе с
        // одиночным выбором обрезается до первого, а не отвергается целиком.
        if ($question->type === SurveyQuestionType::Single && count($chosen) > 1) {
            $chosen = [$chosen[0]];
        }

        $other = $question->allows_other ? trim((string) ($given['other'] ?? '')) : '';

        if ($chosen === [] && $other === '') {
            return null;
        }

        return [
            'options' => $chosen,
            // Свой вариант — рядом с отмеченным, а не вместо: «ещё пригодилось
            // вот это» и «пригодилось только вот это» — разные ответы.
            'other' => $other === '' ? null : $other,
        ];
    }
}
