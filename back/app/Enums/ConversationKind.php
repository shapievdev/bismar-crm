<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Личная переписка или групповая.
 *
 * Различаются они немногим: у личной состав неизменен и имя берётся у
 * собеседника, у групповой есть название, владелец и люди, которых добавляют и
 * убирают. Всё остальное — лента, непрочитанное, вложения — общее.
 */
enum ConversationKind: string
{
    case Direct = 'direct';

    case Group = 'group';

    public function isGroup(): bool
    {
        return $this === self::Group;
    }
}
