<?php

declare(strict_types=1);

namespace App\Enums;

enum NewsStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Published => 'Опубликована',
        };
    }

    /**
     * Видна ли новость тому, кому она адресована.
     *
     * Архива у новостей нет: устаревшую снимают с публикации или удаляют, а
     * третье состояние пришлось бы объяснять на каждом экране.
     */
    public function isVisibleToReaders(): bool
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
