<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Жизнь экзамена — та же, что у курса: собирается, идёт, закончился.
 *
 * Черновик видят только те, кто экзамены ведёт: назначать несобранное нельзя,
 * и сотрудник не должен найти в списке экзамен, в котором ещё нет вопросов.
 * Архив — не удаление: результаты сданного остаются смотрибельными, но новых
 * попыток архив не принимает, какое бы окно у него ни стояло.
 */
enum ExamStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Published => 'Опубликован',
            self::Archived => 'В архиве',
        };
    }

    /** Принимает ли экзамен работы — при прочих условиях вроде окна и срока. */
    public function isOpenToSitters(): bool
    {
        return $this === self::Published;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
