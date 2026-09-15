<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Enums\ExportFormat;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Отчёт по штату в виде файла.
 *
 * Пишется потоком в файл, а не собирается в памяти: выгрузка по компании на
 * тысячу человек — это тысяча строк, и держать их массивом ради того, чтобы
 * потом отдать одной строкой, незачем.
 *
 * **Форматы отличаются содержимым, и это не небрежность.** В книге есть листы, и
 * потому в неё кладётся весь отчёт: сводка, движение, подразделения, причины,
 * стаж — и, если попросили, люди. В CSV листов нет, и «весь отчёт» пришлось бы
 * склеивать в одну простыню с пустыми строками вместо переходов — таблицу, в
 * которой ни отсортировать, ни сложить. Поэтому CSV — это ровно одна таблица:
 * список людей, если выгружают с фамилиями, и разрез по подразделениям, если без.
 *
 * Фамилии — предмет отдельного решения вызывающего, а не следствие формата:
 * выгрузка с людьми попадает в журнал иначе, чем выгрузка со сводкой (152-ФЗ).
 */
final readonly class StaffWorkbook
{
    public function __construct(private StaffReport $report) {}

    /**
     * Пишет отчёт в файл и возвращает число строк данных — то, что уходит в
     * журнал выгрузок.
     *
     * @param  string  $slice  какую цифру раскрывает список людей
     */
    public function write(
        string $path,
        StaffFilters $filters,
        ExportFormat $format,
        bool $withNames,
        string $slice = 'headcount',
    ): int {
        return $format === ExportFormat::Xlsx
            ? $this->writeWorkbook($path, $filters, $withNames, $slice)
            : $this->writeTable($path, $filters, $withNames, $slice);
    }

    /**
     * Имя файла: по нему выгрузку узнают в папке «Загрузки» через месяц.
     */
    public function filename(StaffFilters $filters, ExportFormat $format, bool $withNames): string
    {
        return sprintf(
            'shtat-%s-%s%s.%s',
            $filters->from->format('Y-m-d'),
            $filters->to->format('Y-m-d'),
            $withNames ? '-spisok' : '',
            $format->extension(),
        );
    }

    /* ---------- Книга ---------- */

    private function writeWorkbook(string $path, StaffFilters $filters, bool $withNames, string $slice): int
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $rows = 0;

        $writer->getCurrentSheet()->setName('Сводка');
        $rows += $this->put($writer, ['Показатель', 'Значение'], $this->summaryRows($filters));

        $writer->addNewSheetAndMakeItCurrent()->setName('Движение');
        $rows += $this->put($writer, ['Месяц', 'Принято', 'Уволено'], array_map(
            static fn (array $month): array => [$month['month'], $month['hired'], $month['left']],
            $this->report->movement($filters),
        ));

        $writer->addNewSheetAndMakeItCurrent()->setName('Подразделения');
        $rows += $this->put($writer, ['Подразделение', 'Числится', 'Принято', 'Уволено', 'Текучесть, %'], array_map(
            static fn (array $one): array => [
                $one['name'], $one['headcount'], $one['hired'], $one['left'], $one['turnover'],
            ],
            $this->report->byDepartment($filters),
        ));

        $writer->addNewSheetAndMakeItCurrent()->setName('Причины ухода');
        $rows += $this->put($writer, ['Причина', 'Человек', 'Доля, %'], $this->reasonRows($filters));

        $writer->addNewSheetAndMakeItCurrent()->setName('Стаж');
        $rows += $this->put($writer, ['Группа', 'Стаж', 'Человек'], array_map(
            static fn (array $one): array => [$one['label'], $one['range'], $one['count']],
            $this->report->tenure($filters),
        ));

        if ($withNames) {
            $writer->addNewSheetAndMakeItCurrent()->setName('Люди');
            $rows += $this->put($writer, $this->peopleHeader(), $this->peopleRows($filters, $slice));
        }

        $writer->close();

        return $rows;
    }

    /* ---------- Одна таблица ---------- */

    private function writeTable(string $path, StaffFilters $filters, bool $withNames, string $slice): int
    {
        // Точка с запятой и метка кодировки — ради Excel с русскими
        // настройками: с запятой он сваливает всю строку в первую ячейку, а без
        // метки показывает кириллицу крякозябрами.
        $writer = new CsvWriter(new CsvOptions(FIELD_DELIMITER: ';', SHOULD_ADD_BOM: true));
        $writer->openToFile($path);

        $rows = $withNames
            ? $this->put($writer, $this->peopleHeader(), $this->peopleRows($filters, $slice))
            : $this->put($writer, ['Подразделение', 'Числится', 'Принято', 'Уволено', 'Текучесть, %'], array_map(
                static fn (array $one): array => [
                    $one['name'], $one['headcount'], $one['hired'], $one['left'], $one['turnover'],
                ],
                $this->report->byDepartment($filters),
            ));

        $writer->close();

        return $rows;
    }

    /* ---------- Кухня ---------- */

    /**
     * Шапка и строки одной таблицы. Возвращает число строк данных — шапка в
     * журнале выгрузок не строка.
     *
     * @param  list<string>  $header
     * @param  list<list<null|float|int|string>>  $data
     */
    private function put(WriterInterface $writer, array $header, array $data): int
    {
        $writer->addRow(Row::fromValuesWithStyle($header, (new Style)->withFontBold(true)));

        foreach ($data as $row) {
            // Пустая ячейка вместо `null`: openspout пишет null как пустую
            // строку, но в книге это разные вещи — пусть решает не он.
            $writer->addRow(Row::fromValues(array_map(
                static fn (null|float|int|string $value): float|int|string => $value ?? '',
                $row,
            )));
        }

        return count($data);
    }

    /**
     * Сводка — парами «показатель, значение».
     *
     * Строками, а не колонками: показателей десяток, и в строку они уехали бы
     * за край экрана, а читают их сверху вниз.
     *
     * @return list<list<null|float|int|string>>
     */
    private function summaryRows(StaffFilters $filters): array
    {
        $summary = $this->report->summary($filters);

        $rows = [
            ['Период', $filters->from->format('d.m.Y').' — '.$filters->to->format('d.m.Y')],
            ['Численность на начало', $summary['headcount_start']],
            ['Численность на конец', $summary['headcount_end']],
            ['Принято', $summary['hired']],
            ['Уволено', $summary['left']],
            ['Среднесписочная', $summary['average_headcount']],
            ['Текучесть, %', $summary['turnover']],
            ['Ранняя текучесть, %', $summary['early_turnover']],
            ['Ушли в первые 90 дней', $summary['early_left']],
            ['Средний стаж работающих, мес.', $summary['average_tenure']],
            ['Средний срок работы ушедших, мес.', $summary['average_life']],
        ];

        // Прочерк, а не ноль: «онбординг 0 %» и «онбординг не считается» —
        // разные новости, и первая из них ложь, когда верна вторая.
        $rows[] = ['Онбординг, %', $summary['onboarding'] ?? '—'];
        $rows[] = ['Без даты приёма', $summary['without_hire_date']];

        return $rows;
    }

    /**
     * @return list<list<null|float|int|string>>
     */
    private function reasonRows(StaffFilters $filters): array
    {
        $reasons = $this->report->reasons($filters);

        $rows = array_map(
            static fn (array $one): array => [$one['label'], $one['count'], $one['share']],
            $reasons['rows'],
        );

        // Непроставленные — отдельной строкой и без доли: они не причина, а
        // незаполненное поле, и растворять их в процентах нельзя.
        if ($reasons['unknown'] > 0) {
            $rows[] = ['Причина не указана', $reasons['unknown'], ''];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function peopleHeader(): array
    {
        return [
            'Сотрудник', 'Должность', 'Подразделения', 'Режим',
            'Принят', 'Уволен', 'Стаж, мес.', 'Положение', 'Причина ухода',
            'Группа по стажу', 'Теги',
        ];
    }

    /**
     * @return list<list<null|float|int|string>>
     */
    private function peopleRows(StaffFilters $filters, string $slice): array
    {
        return array_map(static fn (array $person): array => [
            $person['name'],
            $person['job_title'],
            implode(', ', $person['departments']),
            $person['work_mode_label'],
            $person['hired_at'],
            $person['dismissed_at'],
            $person['tenure_months'],
            $person['status_label'],
            $person['dismissal_reason_label'],
            $person['tenure_tag_label'],
            implode(', ', $person['tags']),
        ], $this->report->people($filters, $slice));
    }
}
