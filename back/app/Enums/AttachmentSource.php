<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Откуда взялся приложенный файл.
 *
 * Разница не в способе загрузки, а в том, кто отвечает за доступ. Наш файл
 * лежит в закрытой корзине, и кто его увидит, решаем мы — подписанной ссылкой.
 * Файл на Google Диске остаётся у Google: мы храним только его номер, а пустят
 * к нему или нет, решает Google по своим настройкам доступа. Поэтому это не
 * два способа хранить одно и то же, а два разных рода вложений.
 */
enum AttachmentSource: string
{
    case Storage = 'storage';
    case GoogleDrive = 'google_drive';

    public function isGoogleDrive(): bool
    {
        return $this === self::GoogleDrive;
    }
}
