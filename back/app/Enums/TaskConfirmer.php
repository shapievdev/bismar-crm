<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Кто закрывает задание этапа.
 *
 * «Сам» — для заданий, где отметка и есть исполнение («прочитай правила
 * зала»). «Ответственный» — где исполнение видел другой человек («собери
 * первый заказ при наставнике»): отметка стажёра там только заявка, зачёт
 * ставит подтверждение.
 */
enum TaskConfirmer: string
{
    case Trainee = 'self';
    case Responsible = 'responsible';

    public function label(): string
    {
        return match ($this) {
            self::Trainee => 'Отмечает сам стажёр',
            self::Responsible => 'Подтверждает ответственный',
        };
    }

    public function needsConfirmation(): bool
    {
        return $this === self::Responsible;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $confirmer): string => $confirmer->value, self::cases());
    }
}
