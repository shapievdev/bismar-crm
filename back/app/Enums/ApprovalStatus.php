<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Чем кончилось согласование — круг целиком или решение одного человека.
 *
 * Одно перечисление на оба намеренно: слова у них те же, и вторая пара названий
 * для того же самого разошлась бы с первой на первой правке. У решения человека
 * бывают только первые три: «отозвано» — состояние круга, а не чей-то ответ.
 */
enum ApprovalStatus: string
{
    /** Круг идёт; у человека — «ещё не ответил». */
    case Pending = 'pending';

    case Approved = 'approved';

    /** Вернули автору на исправление — с причиной, без неё возврата не бывает. */
    case Returned = 'returned';

    /**
     * Круг закрыт, не дождавшись ответов: автор выложил материал сам либо
     * отозвал отправку. Решением человека это быть не может.
     */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ждёт согласования',
            self::Approved => 'Согласовано',
            self::Returned => 'Возвращено на исправление',
            self::Cancelled => 'Согласование отменено',
        };
    }

    /** Решение человека: круг ждёт, согласовал, вернул. */
    public function isDecision(): bool
    {
        return $this !== self::Cancelled;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
