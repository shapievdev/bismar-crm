<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\SurveyQuestionType;
use App\Models\Lesson;
use App\Models\News;
use App\Models\Regulation;
use App\Models\RegulationVersion;
use App\Models\Survey;
use App\Models\SurveyOption;
use App\Models\SurveyQuestion;
use Illuminate\Support\Facades\DB;

/**
 * Сохраняет опрос материала целиком: редактор присылает его весь, поэтому одно
 * действие вместо пары «создать — изменить».
 *
 * Целиком — но не заново, и это то же условие, что у теста (см. SaveQuiz):
 * вопросы и варианты, присланные со своим номером, правятся на месте. Номера
 * лежат в снимках отправленного, и пересозданный вопрос разом отвязывает от себя
 * всё, что люди уже ответили, — сводка после правки заголовка показала бы, что
 * на вопрос не ответил никто.
 *
 * Убранное из присланного удаляется вместе со своей частью ответов. Иначе снятый
 * вопрос жил бы в опросе вечно — но правка опроса, который уже кто-то прошёл,
 * всегда стоит чьих-то ответов, и предупреждать об этом должен экран.
 */
final readonly class SaveSurvey
{
    /**
     * @param  array{
     *     title: string,
     *     description?: ?string,
     *     is_required?: bool,
     *     is_anonymous?: bool,
     *     closes_at?: ?string,
     *     thanks?: ?string,
     *     questions: array<int, array{
     *         id?: ?int,
     *         text: string,
     *         type: string,
     *         is_required?: bool,
     *         allows_other?: bool,
     *         scale_min?: ?int,
     *         scale_max?: ?int,
     *         scale_min_label?: ?string,
     *         scale_max_label?: ?string,
     *         options?: array<int, array{id?: ?int, text: string}>
     *     }>
     * } $attributes
     */
    public function handle(Lesson|Regulation|RegulationVersion|News $owner, array $attributes): Survey
    {
        return DB::transaction(function () use ($owner, $attributes): Survey {
            $survey = Survey::updateOrCreate(
                [
                    // Вид и номер вместе: урок №3 и новость №3 — разные вещи.
                    'surveyable_type' => $owner->getMorphClass(),
                    'surveyable_id' => $owner->getKey(),
                ],
                [
                    'title' => $attributes['title'],
                    'description' => $attributes['description'] ?? null,
                    'is_required' => $attributes['is_required'] ?? false,
                    'is_anonymous' => $attributes['is_anonymous'] ?? false,
                    'closes_at' => $attributes['closes_at'] ?? null,
                    'thanks' => $this->trimmed($attributes['thanks'] ?? null),
                ],
            );

            $survey->load('questions.options');

            $kept = [];

            foreach (array_values($attributes['questions']) as $position => $data) {
                $kept[] = $this->question($survey, $data, $position)->getKey();
            }

            $survey->questions()->whereNotIn('id', $kept)->delete();

            return $survey->load('questions.options');
        });
    }

    /**
     * Вопрос: тот же, что был, или новый.
     *
     * Свой ли это вопрос, спрашивается у уже загруженного опроса, а не у базы:
     * номер приходит из браузера, и чужой вопрос по нему подставить нельзя —
     * незнакомый номер просто заводит новый.
     *
     * @param  array{id?: ?int, text: string, type: string, is_required?: bool, allows_other?: bool, scale_min?: ?int, scale_max?: ?int, scale_min_label?: ?string, scale_max_label?: ?string, options?: array<int, array{id?: ?int, text: string}>}  $data
     */
    private function question(Survey $survey, array $data, int $position): SurveyQuestion
    {
        $type = SurveyQuestionType::from($data['type']);

        $attributes = [
            'text' => $data['text'],
            'type' => $type,
            'is_required' => $data['is_required'] ?? true,

            // «Свой вариант» — только у выбора: у шкалы и у письменного ответа
            // он был бы полем рядом с полем.
            'allows_other' => $type->isChoice() && ($data['allows_other'] ?? false),

            // Границы и подписи — только у шкалы. У прочих видов они бы
            // остались в базе после смены вида и однажды вернулись бы на экран.
            'scale_min' => $type->isScale()
                ? ($data['scale_min'] ?? SurveyQuestion::DEFAULT_SCALE_MIN)
                : null,
            'scale_max' => $type->isScale()
                ? ($data['scale_max'] ?? SurveyQuestion::DEFAULT_SCALE_MAX)
                : null,
            'scale_min_label' => $type->isScale() ? $this->trimmed($data['scale_min_label'] ?? null) : null,
            'scale_max_label' => $type->isScale() ? $this->trimmed($data['scale_max_label'] ?? null) : null,

            'position' => $position,
        ];

        $known = ($data['id'] ?? null) === null
            ? null
            : $survey->questions->firstWhere('id', $data['id']);

        $question = $known instanceof SurveyQuestion
            ? tap($known)->update($attributes)
            : $survey->questions()->create($attributes);

        // Варианты нужны только выбору: сменив вид вопроса на шкалу, автор
        // оставил бы за ней список, которого на экране уже нет.
        $this->options($question, $type->isChoice() ? ($data['options'] ?? []) : []);

        return $question;
    }

    /**
     * Варианты вопроса — по тем же правилам, что и сами вопросы: номер варианта
     * лежит в снимках ответов, и пересозданный вариант стирает из сводки то, что
     * люди отмечали.
     *
     * @param  array<int, array{id?: ?int, text: string}>  $options
     */
    private function options(SurveyQuestion $question, array $options): void
    {
        $question->loadMissing('options');

        $kept = [];

        foreach (array_values($options) as $position => $data) {
            $attributes = ['text' => $data['text'], 'position' => $position];

            $known = ($data['id'] ?? null) === null
                ? null
                : $question->options->firstWhere('id', $data['id']);

            $kept[] = $known instanceof SurveyOption
                ? tap($known)->update($attributes)->getKey()
                : $question->options()->create($attributes)->getKey();
        }

        $question->options()->whereNotIn('id', $kept)->delete();
    }

    /** Пустая строка — это «не задано», а не подпись из пробелов. */
    private function trimmed(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
