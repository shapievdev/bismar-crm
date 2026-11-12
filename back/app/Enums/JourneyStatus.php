<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Что происходит с маршрутом сотрудника.
 *
 * «Прерван» здесь не бывает: маршрут — часть карточки человека, один и
 * навсегда, и прекращается вместе с ней. Болезнь и отпуск закрывает пауза:
 * она глушит уведомления и сдвигает расписание при возвращении.
 */
enum JourneyStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Идёт',
            self::Paused => 'Приостановлен',
            self::Completed => 'Завершён',
        };
    }

    /** Идёт или ждёт возвращения — в обоих случаях ещё не дописан. */
    public function isAlive(): bool
    {
        return $this === self::Active || $this === self::Paused;
    }

    /** Шлют ли по ней уведомления. */
    public function isNotified(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
