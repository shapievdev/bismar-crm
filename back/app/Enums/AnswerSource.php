<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Где в уроке написан ответ.
 *
 * Урок состоит из трёх вещей, и ответ может лежать в любой из них: в записи
 * занятия, в приложенном файле или в самом тексте. Указать урок целиком мало —
 * урок бывает на десять экранов и на час записи, и «проверьте сами»
 * превращается в поиск глазами.
 */
enum AnswerSource: string
{
    case Text = 'text';
    case Video = 'video';
    case Attachment = 'attachment';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Текст урока',
            self::Video => 'Видео урока',
            self::Attachment => 'Приложенный файл',
        };
    }

    /**
     * Чем это место уточняется внутри своего вида.
     *
     * Ровно одно поле на вид, и остальные при этом обязаны остаться пустыми:
     * строка с таймкодом и номером страницы разом не значит ничего.
     */
    public function locatorColumn(): string
    {
        return match ($this) {
            self::Text => 'source_block_id',
            self::Video => 'source_seconds',
            self::Attachment => 'source_page',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
