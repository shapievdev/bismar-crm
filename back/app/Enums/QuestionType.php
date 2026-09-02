<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Чем человек отвечает на вопрос.
 *
 * Выбор варианта проверяется сравнением с ключом, письменный ответ — схожестью
 * с эталоном, который написал автор (решение пользователя 2026-09-02), таблица —
 * заполненностью: верны ли в ней числа, приложение знать не может. Три способа
 * проверки, пять видов вопроса.
 */
enum QuestionType: string
{
    /** Exactly one option is correct. */
    case Single = 'single';

    /** Several options are correct and all must be picked. */
    case Multiple = 'multiple';

    /** Строка своими словами: цифра, срок, название. */
    case Text = 'text';

    /** Развёрнутый ответ: объяснение, вывод, список мер. */
    case LongText = 'long_text';

    /** Таблица: месяцы, недели, статьи расходов — работа, а не ответ. */
    case Table = 'table';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Один вариант',
            self::Multiple => 'Несколько вариантов',
            self::Text => 'Короткий ответ',
            self::LongText => 'Развёрнутый ответ',
            self::Table => 'Таблица',
        };
    }

    /** Отвечают выбором из готовых вариантов. */
    public function isChoice(): bool
    {
        return $this === self::Single || $this === self::Multiple;
    }

    /**
     * Отвечают своими словами.
     *
     * У такого вопроса нет вариантов и есть эталон: проверка сравнивает
     * написанное с ним по смыслу, а не по буквам.
     */
    public function isWritten(): bool
    {
        return $this === self::Text || $this === self::LongText;
    }

    /**
     * Заполняют таблицу.
     *
     * Ни ключа, ни эталона у неё нет: верны ли числа, приложение знать не
     * может — это работа, которую читает человек. Зачёт по заполненности,
     * см. QuestionTable.
     */
    public function isTable(): bool
    {
        return $this === self::Table;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
