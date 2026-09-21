<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Чем отвечают на вопрос опроса.
 *
 * Своё перечисление, а не QuestionType от тестов, и это не оплошность. Там виды
 * заведены по способу проверки: выбор сверяется с ключом, письменный ответ — с
 * эталоном, таблица — с заполненностью. Здесь проверять нечего вовсе, и виды
 * заведены по тому, чем удобно высказаться: шкала нужна опросу и бессмысленна в
 * тесте, а таблица — наоборот. Общее перечисление обещало бы каждой стороне
 * виды, которых она не умеет.
 */
enum SurveyQuestionType: string
{
    /** Один вариант из списка. */
    case Single = 'single';

    /** Несколько вариантов сразу. */
    case Multiple = 'multiple';

    /** Оценка по шкале: от «совсем нет» до «да, полностью». */
    case Scale = 'scale';

    /** Строка: должность, город, число. */
    case Text = 'text';

    /** Несколько строк: что улучшить, чего не хватило. */
    case LongText = 'long_text';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Один вариант',
            self::Multiple => 'Несколько вариантов',
            self::Scale => 'Шкала',
            self::Text => 'Короткий ответ',
            self::LongText => 'Развёрнутый ответ',
        };
    }

    /** Отвечают, отмечая готовые варианты. */
    public function isChoice(): bool
    {
        return $this === self::Single || $this === self::Multiple;
    }

    /** Отвечают числом на шкале. */
    public function isScale(): bool
    {
        return $this === self::Scale;
    }

    /** Отвечают своими словами. */
    public function isWritten(): bool
    {
        return $this === self::Text || $this === self::LongText;
    }

    /**
     * Что показать в форме: вид, его имя и объяснение.
     *
     * @return list<array{value: string, label: string, hint: string}>
     */
    public static function options(): array
    {
        return [
            [
                'value' => self::Single->value,
                'label' => self::Single->label(),
                'hint' => 'Отмечают что-то одно. Можно разрешить свой вариант.',
            ],
            [
                'value' => self::Multiple->value,
                'label' => self::Multiple->label(),
                'hint' => 'Отмечают сколько угодно. Можно разрешить свой вариант.',
            ],
            [
                'value' => self::Scale->value,
                'label' => self::Scale->label(),
                'hint' => 'Оценка числом — от 1 до 5 или до 10, с подписями у концов.',
            ],
            [
                'value' => self::Text->value,
                'label' => self::Text->label(),
                'hint' => 'Одна строка своими словами.',
            ],
            [
                'value' => self::LongText->value,
                'label' => self::LongText->label(),
                'hint' => 'Несколько строк: что улучшить, чего не хватило.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
