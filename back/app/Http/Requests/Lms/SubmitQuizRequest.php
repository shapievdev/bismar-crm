<?php

declare(strict_types=1);

namespace App\Http\Requests\Lms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Присланные ответы.
 *
 * У вопроса с выбором ответ — список номеров вариантов, у письменного — строка
 * своими словами, у таблицы — строки со значениями ячеек. Все виды приходят
 * одним полем `answers`, потому что экран присылает бланк целиком, а разбирать,
 * чем именно отвечали, — дело оценки: там же лежит и вид вопроса.
 */
final class SubmitQuizRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Номер вопроса => выбранные варианты или написанный ответ.
            // Неотвеченные вопросы можно не присылать — они просто не берут
            // балл.
            'answers' => ['present', 'array'],
            'answers.*' => ['nullable'],
            // Ячейку таблицы и номер варианта различает уже разбор: правило
            // «только целые» отсекло бы таблицы, а «только строки» — выбор.
            'answers.*.*' => ['nullable'],
            'answers.*.*.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, list<int>|list<list<string>>|string>
     */
    public function answers(): array
    {
        /** @var array<int|string, mixed> $answers */
        $answers = $this->validated('answers', []);

        $normalised = [];

        foreach ($answers as $questionId => $answer) {
            if (is_array($answer)) {
                $normalised[(int) $questionId] = $this->isTable($answer)
                    ? $this->rows($answer)
                    : array_map(intval(...), array_values($answer));

                continue;
            }

            // Строка обрезается по краям и по длине: развёрнутый ответ — это
            // абзац-два, а не мегабайт, присланный запросом.
            $written = trim((string) $answer);

            if ($written !== '') {
                $normalised[(int) $questionId] = mb_substr($written, 0, 4000);
            }
        }

        return $normalised;
    }

    /**
     * Ответ на таблицу отличается от списка вариантов формой: там номера,
     * здесь строки из ячеек.
     *
     * @param  array<int|string, mixed>  $answer
     */
    private function isTable(array $answer): bool
    {
        foreach ($answer as $value) {
            return is_array($value);
        }

        return false;
    }

    /**
     * Строки таблицы: ячейки приводятся к строкам и обрезаются — в ячейку пишут
     * сумму или пару слов, а не абзац.
     *
     * @param  array<int|string, mixed>  $answer
     * @return list<list<string>>
     */
    private function rows(array $answer): array
    {
        $rows = [];

        foreach (array_values($answer) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = array_values(array_map(
                static fn ($cell): string => mb_substr(trim((string) $cell), 0, 500),
                $row,
            ));
        }

        return $rows;
    }
}
