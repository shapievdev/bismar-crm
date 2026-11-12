<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Где встречаются: вживую или по ссылке. От формата зависит смысл поля
 * `place`: адрес торгового зала или ссылка на созвон.
 */
enum MeetingFormat: string
{
    case Offline = 'offline';
    case Online = 'online';

    public function label(): string
    {
        return match ($this) {
            self::Offline => 'Очно',
            self::Online => 'Онлайн',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $format): string => $format->value, self::cases());
    }
}
