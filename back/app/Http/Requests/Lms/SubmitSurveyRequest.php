<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Присланные ответы опроса.
 *
 * Ответ на вопрос приходит одним предметом, а не полем на вид: `{"options":
 * [3,4], "other": "…"}`, `{"scale": 4}`, `{"text": "…"}`. Так экран присылает
 * бланк целиком и не решает за сервер, чем именно отвечали, — вид вопроса лежит
 * в опросе, там же и разбирается (см. SubmitSurvey).
 *
 * Проверяется здесь только форма. Своё ли это вопросы, свои ли варианты и есть ли
 * такое деление у шкалы — сверяется с самим опросом, потому что знать об этом
 * может только он.
 */
final class SubmitSurveyRequest extends FormRequest
{
    /** Развёрнутый ответ — это абзац-два, а не мегабайт, присланный запросом. */
    private const MAX_TEXT = 4000;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Номер вопроса => ответ. Пропущенные вопросы можно не присылать
            // вовсе: пустой ответ и отсутствующий — одно и то же.
            'answers' => ['present', 'array'],
            'answers.*' => ['nullable', 'array'],
            'answers.*.options' => ['sometimes', 'array', 'max:30'],
            'answers.*.options.*' => ['required', 'integer'],
            'answers.*.other' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
            'answers.*.scale' => ['nullable', 'integer'],
            'answers.*.text' => ['nullable', 'string', 'max:'.self::MAX_TEXT],
        ];
    }

    /**
     * Ответы, приведённые к виду, в котором их ждёт SubmitSurvey.
     *
     * Строки обрезаются по краям и по длине здесь же: дальше они пойдут в снимок,
     * и чистить их на выдаче было бы поздно.
     *
     * @return array<int, array{options?: list<int>, other?: ?string, scale?: ?int, text?: ?string}>
     */
    public function answers(): array
    {
        /** @var array<int|string, mixed> $answers */
        $answers = $this->validated('answers', []);

        $normalised = [];

        foreach ($answers as $questionId => $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $normalised[(int) $questionId] = [
                'options' => array_map(
                    intval(...),
                    array_values(is_array($answer['options'] ?? null) ? $answer['options'] : []),
                ),
                'other' => $this->text($answer['other'] ?? null),
                'scale' => isset($answer['scale']) ? (int) $answer['scale'] : null,
                'text' => $this->text($answer['text'] ?? null),
            ];
        }

        return $normalised;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, self::MAX_TEXT);
    }
}
