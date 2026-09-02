<?php

declare(strict_types=1);

namespace App\Support\Lms;

/**
 * Таблица как вопрос: столбцы, строки и правило зачёта.
 *
 * Взято с настоящих бланков аттестации, и потому вид один, а случаев пять:
 *
 *  - 12 месяцев: подписей у строк нет, все ячейки заполняет сотрудник;
 *  - 13 недель: у строк подписи («Неделя 1»), их сотрудник не правит;
 *  - четыре причины разрыва: то же, но подписи содержательные;
 *  - расходы: часть строк подписана, часть оставлена пустыми, и строки можно
 *    добавлять;
 *  - тип расхода в ячейке — выбор из списка, а не свободный текст.
 *
 * Всё это описывается одним определением: заголовок ведущего столбца (null —
 * ведущего столбца нет), список столбцов, список строк с подписями и признак
 * «строки можно добавлять».
 *
 * **Эталон по ячейкам — необязательный.** В прогнозе на тринадцать недель
 * правильных чисел не существует: это данные компании сотрудника, и читает их
 * человек на защите. А в столбце «Тип расхода» правильный ответ есть — аренда
 * постоянная, закупка переменная, — и его можно сверить. Поэтому у каждой
 * заготовленной строки есть список ожидаемых значений по столбцам: где автор
 * его заполнил, ячейка сверяется, где оставил пустым — только требуется
 * заполнить (решение пользователя 2026-09-02).
 *
 * Ведущая ячейка подписанной строки приходит с самой подписью: экран её не даёт
 * править, но присылает вместе с остальными — так ширина строки одна на всю
 * таблицу, и правило зачёта не приходится считать для каждой строки отдельно.
 *
 * **Зачёт.** Вопрос берётся, когда заготовленные строки заполнены целиком и
 * все сверяемые ячейки совпали с эталоном. Добавленная строка — либо целиком,
 * либо пусто: полупустая появилась случайно, и держать из-за неё всю сдачу
 * незачем; эталона у добавленных строк нет по определению — их придумывает сам
 * сотрудник.
 *
 * Ячейки сверяются буквально, а не по смыслу: в ячейке стоит сумма или короткая
 * метка, и близость по смыслу у «Постоянные» и «Переменные» была бы почти
 * полной — эмбеддинги зачли бы неверный ответ. Числа сравниваются как числа
 * («1 200 000» и «1200000» — одно и то же), остальное — как текст без учёта
 * регистра, пробелов и знаков.
 */
final readonly class QuestionTable
{
    public const TEXT = 'text';

    public const SELECT = 'select';

    /**
     * Приводит присланное определение к виду, в котором его можно хранить и
     * рисовать: лишние поля отброшены, недостающие заполнены.
     *
     * @param  array<string, mixed>|null  $definition
     * @return array{
     *     row_label_title: ?string,
     *     columns: list<array{title: string, kind: string, options: list<string>}>,
     *     rows: list<array{label: string, expected: list<string>}>,
     *     can_add_rows: bool
     * }|null
     */
    public static function normalise(?array $definition): ?array
    {
        if ($definition === null) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $columns */
        $columns = is_array($definition['columns'] ?? null) ? $definition['columns'] : [];
        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($definition['rows'] ?? null) ? $definition['rows'] : [];

        $title = trim((string) ($definition['row_label_title'] ?? ''));

        return [
            // Пустой заголовок означает «ведущего столбца нет»: подписи строк
            // тогда не рисуются вовсе, как в таблице на двенадцать месяцев.
            'row_label_title' => $title === '' ? null : $title,

            'columns' => array_values(array_map(static function (array $column): array {
                $kind = ($column['kind'] ?? self::TEXT) === self::SELECT ? self::SELECT : self::TEXT;

                /** @var array<int, mixed> $options */
                $options = is_array($column['options'] ?? null) ? $column['options'] : [];

                return [
                    'title' => trim((string) ($column['title'] ?? '')),
                    'kind' => $kind,
                    // Список вариантов нужен только ячейке-выбору; у текстовой
                    // он был бы обещанием, которого экран не выполняет.
                    'options' => $kind === self::SELECT
                        ? array_values(array_filter(array_map(
                            static fn ($option): string => trim((string) $option),
                            $options,
                        ), static fn (string $option): bool => $option !== ''))
                        : [],
                ];
            }, $columns)),

            'rows' => array_values(array_map(static function (array $row) use ($columns): array {
                /** @var array<int, mixed> $expected */
                $expected = is_array($row['expected'] ?? null) ? array_values($row['expected']) : [];

                return [
                    'label' => trim((string) ($row['label'] ?? '')),
                    // Ожидаемые значения идут по столбцам: пустое означает
                    // «эту ячейку не сверяем, её просто нужно заполнить».
                    'expected' => array_map(
                        static fn ($value): string => trim((string) ($value ?? '')),
                        array_slice(array_pad($expected, count($columns), ''), 0, count($columns)),
                    ),
                ];
            }, $rows)),

            'can_add_rows' => (bool) ($definition['can_add_rows'] ?? false),
        ];
    }

    /**
     * Заполнена ли таблица и совпали ли сверяемые ячейки.
     *
     * `wrong` — координаты ячеек, которые разошлись с эталоном: по ним разбор
     * отмечает неверное. «Не зачтено» без указания места отправляет человека
     * перепроверять шестьдесят ячеек вслепую.
     *
     * @param  array{
     *     row_label_title: ?string,
     *     columns: list<array{title: string, kind: string, options: list<string>}>,
     *     rows: list<array{label: string, expected: list<string>}>,
     *     can_add_rows: bool
     * } $definition
     * @param  list<list<string>>  $filled  строки ответа, в каждой — значения ячеек
     * @return array{
     *     is_accepted: bool,
     *     filled_cells: int,
     *     required_cells: int,
     *     checked_cells: int,
     *     correct_cells: int,
     *     wrong: list<array{row: int, cell: int}>
     * }
     */
    public static function judge(array $definition, array $filled): array
    {
        $width = self::width($definition);
        $preset = count($definition['rows']);
        $required = $width * $preset;
        $leading = $width > count($definition['columns']) ? 1 : 0;

        $filledCells = 0;
        $missing = 0;
        $checked = 0;
        $correct = 0;
        $wrong = [];

        foreach (array_values($filled) as $index => $row) {
            $cells = array_map(static fn ($cell): string => trim((string) $cell), array_values($row));
            $cells = array_slice(array_pad($cells, $width, ''), 0, $width);

            $inRow = count(array_filter($cells, static fn (string $cell): bool => $cell !== ''));

            // Заготовленная строка должна быть заполнена целиком; добавленная —
            // либо целиком, либо пусто.
            $isPreset = $index < $preset;

            if ($isPreset) {
                // Счёт ведём по требуемому: добавленные строки — сверх нормы, и
                // «заполнено 9 из 6» читалось бы как ошибка.
                $filledCells += $inRow;
            }

            if ($isPreset && $inRow < $width) {
                $missing++;
            }

            if (! $isPreset && $inRow > 0 && $inRow < $width) {
                $missing++;
            }

            if (! $isPreset) {
                // У добавленной строки эталона нет по определению: её придумал
                // сам сотрудник.
                continue;
            }

            foreach ($definition['rows'][$index]['expected'] as $column => $expected) {
                if ($expected === '') {
                    continue;
                }

                $checked++;
                $cell = $cells[$column + $leading] ?? '';

                if (self::matches($cell, $expected)) {
                    $correct++;

                    continue;
                }

                $wrong[] = ['row' => $index, 'cell' => $column + $leading];
            }
        }

        // Строк пришло меньше, чем заготовлено, — остальные считаются пустыми.
        $missing += max(0, $preset - count($filled));

        return [
            'is_accepted' => $required > 0 && $missing === 0 && $wrong === [],
            'filled_cells' => $filledCells,
            'required_cells' => $required,
            'checked_cells' => $checked,
            'correct_cells' => $correct,
            'wrong' => $wrong,
        ];
    }

    /**
     * Совпала ли ячейка с эталоном.
     *
     * Числа сравниваются как числа: «1 200 000», «1200000» и «1200000.00» —
     * одна и та же сумма, и придираться к пробелам значит браковать верный
     * ответ. Прочее — как текст без учёта регистра, пробелов и знаков: «Постоянные»
     * и «постоянные,» одно и то же.
     */
    private static function matches(string $given, string $expected): bool
    {
        $leftNumber = self::number($given);
        $rightNumber = self::number($expected);

        if ($leftNumber !== null && $rightNumber !== null) {
            // Сотые доли рубля в бланке не решают ничего, а расхождение в них
            // возникает от округления при переносе из учётной системы.
            return abs($leftNumber - $rightNumber) < 0.01;
        }

        return self::plain($given) === self::plain($expected);
    }

    private static function number(string $value): ?float
    {
        // Пробелы всех видов — разделители разрядов, запятая — десятичная.
        $cleaned = str_replace([' ', "\u{00a0}", "\u{202f}", ','], ['', '', '', '.'], $value);

        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    private static function plain(string $value): string
    {
        $lowered = mb_strtolower(trim($value));

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lowered));
    }

    /**
     * Сколько ячеек в строке: столбцы плюс ведущий, если сотрудник его
     * заполняет — то есть когда подпись строки пуста и её вписывают сами.
     *
     * @param  array{row_label_title: ?string, columns: list<array<string, mixed>>, rows: list<array{label: string}>, can_add_rows: bool}  $definition
     */
    public static function width(array $definition): int
    {
        $leading = $definition['row_label_title'] !== null
            && (self::hasBlankLabels($definition) || $definition['can_add_rows']);

        return count($definition['columns']) + ($leading ? 1 : 0);
    }

    /**
     * @param  array{rows: list<array{label: string}>}  $definition
     */
    private static function hasBlankLabels(array $definition): bool
    {
        foreach ($definition['rows'] as $row) {
            if ($row['label'] === '') {
                return true;
            }
        }

        return false;
    }
}
