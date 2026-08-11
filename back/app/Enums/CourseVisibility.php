<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Кому курс виден вообще.
 *
 * Не то же самое, что статус. Статус отвечает, готов ли материал; видимость —
 * кому он предназначен. Опубликованный приватный курс готов полностью, просто
 * не для всех, а черновик публичного не виден никому, кроме редакторов, — и
 * сводить эти два вопроса к одному полю значило бы, что материал нельзя
 * опубликовать, не открыв его всей компании.
 */
enum CourseVisibility: string
{
    case Public = 'public';
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Открытый',
            self::Private => 'Приватный',
        };
    }

    /**
     * Пояснение к переключателю: одного слова мало, чтобы решиться.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Public => 'Курс виден всем, кто может читать базу знаний.',
            self::Private => 'Курс виден только автору и тем, кого он добавил.',
        };
    }

    public function isPrivate(): bool
    {
        return $this === self::Private;
    }
}
