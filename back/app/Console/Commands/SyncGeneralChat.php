<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Chat\GeneralChat;
use Illuminate\Console\Command;

/**
 * Вносит в общий чат всех работающих.
 *
 * В обычной жизни не нужна: сотрудника вносит его заведение, а список
 * переписок при каждом открытии проверяет вошедшего. Нужна после того, как
 * учётные записи появились мимо приложения — выгрузкой из 1С, восстановлением
 * базы, руками в консоли.
 *
 * Безвредна при повторе: состав сводится upsert'ом, и отметки читателя — до
 * какого места дочитал, приглушил ли — она не трогает.
 */
final class SyncGeneralChat extends Command
{
    protected $signature = 'chat:general';

    protected $description = 'Внести всех работающих сотрудников в общий чат';

    public function handle(GeneralChat $general): int
    {
        $count = $general->includeEveryone();

        $this->info("В общем чате: {$count}.");

        return self::SUCCESS;
    }
}
