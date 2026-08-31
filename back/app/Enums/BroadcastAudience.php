<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Кому уходит рассылка.
 *
 * Адресат один: рассылка уходит один раз и в историю попадает тем, чем была
 * отправлена. Отдел и группа существуют потому, что оба списка уже есть, —
 * «складу» или «наставникам» отправить проще, чем отмечать двадцать человек по
 * одному. Отдел при этом включает свои подотделы, группа — ровно тех, кого в
 * неё внесли.
 */
enum BroadcastAudience: string
{
    case Everyone = 'everyone';
    case Selected = 'selected';
    case Department = 'department';
    case Group = 'group';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'Всем сотрудникам',
            self::Selected => 'Выбранным',
            self::Department => 'Отделу',
            self::Group => 'Группе',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
