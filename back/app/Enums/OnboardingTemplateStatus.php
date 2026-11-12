<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Жизнь шаблона маршрута — та же, что у курса и экзамена.
 *
 * Назначать можно только опубликованный: черновик — обещание программы, а не
 * программа. Архив не рвёт идущих: их журналы снапшотили своё при назначении
 * и дойдут до конца по-старому; архив лишь закрывает новые назначения.
 */
enum OnboardingTemplateStatus: string
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

    /** Можно ли по этому шаблону назначать новых стажёров. */
    public function isAssignable(): bool
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
