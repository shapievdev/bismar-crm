<?php

declare(strict_types=1);

namespace App\Enums;

enum QuestionType: string
{
    /** Exactly one option is correct. */
    case Single = 'single';

    /** Several options are correct and all must be picked. */
    case Multiple = 'multiple';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Один вариант',
            self::Multiple => 'Несколько вариантов',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
