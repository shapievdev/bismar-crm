<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * В чём отчёт покидает систему.
 *
 * Два формата, потому что ими пользуются по-разному: книгу открывают и смотрят,
 * а простую таблицу подставляют в чужую формулу. Оттого и содержимое у них
 * разное, а не одно и то же в двух обёртках, — см. StaffWorkbook.
 */
enum ExportFormat: string
{
    case Xlsx = 'xlsx';
    case Csv = 'csv';

    public function mime(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            // Кодировка в заголовке обязательна: без неё кириллицу в CSV
            // браузер и почтовый клиент читают как придётся.
            self::Csv => 'text/csv; charset=UTF-8',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }
}
