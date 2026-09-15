<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Почему человек ушёл.
 *
 * Закрытым списком, а не текстом: текст не складывается в доли. «По
 * собственному», «по собственному желанию» и «сам ушёл» — три разные строки и
 * одна причина, и в отчёте они встали бы тремя полосками, из которых ничего не
 * следует.
 *
 * «Не вышел после найма» и «не прошёл испытательный срок» стоят отдельно от
 * прочих намеренно: это не текучесть, а брак найма, и лечится он не удержанием,
 * а отбором. Сложенные с остальными, они прячут ровно ту проблему, ради которой
 * отчёт и смотрят.
 */
enum DismissalReason: string
{
    case Own = 'own';

    case Agreement = 'agreement';

    case Employer = 'employer';

    case ProbationFailed = 'probation-failed';

    case NoShow = 'no-show';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'По собственному',
            self::Agreement => 'По соглашению',
            self::Employer => 'Инициатива работодателя',
            self::ProbationFailed => 'Не прошёл испытательный срок',
            self::NoShow => 'Не вышел после найма',
            self::Other => 'Другое',
        };
    }

    /**
     * Брак найма, а не текучесть: человек не прижился с самого начала.
     *
     * Считается отдельно, потому что и делать по нему надо другое — чинить
     * отбор и первые недели, а не удержание.
     */
    public function isHiringMiss(): bool
    {
        return $this === self::ProbationFailed || $this === self::NoShow;
    }
}
