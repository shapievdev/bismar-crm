<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Regulation;
use App\Models\RegulationAcknowledgement;
use App\Models\RegulationVersion;
use App\Models\User;

/**
 * «Прочитал и понял» — весь прогресс, какой у регламента бывает.
 *
 * Ставится один раз и не снимается. Повторный вызов — не ошибка и не вторая
 * строка: человек мог нажать дважды, а два браузера — одновременно, и
 * уникальный ключ в таблице разрешает этот спор за нас.
 *
 * Версия, по которой отметились, записывается пометкой на той же строке
 * (2026-09-12), а не второй отметкой: версия у человека одна, и требовать
 * ознакомления со всеми значило бы требовать прочитать чужие правила. Первая
 * отметка и решает: прочитавший свою версию ознакомлен с документом, и переход
 * в другую группу задним числом этого не отменяет.
 */
final readonly class AcknowledgeRegulation
{
    public function handle(
        Regulation $regulation,
        User $reader,
        ?RegulationVersion $version = null,
    ): RegulationAcknowledgement {
        return RegulationAcknowledgement::firstOrCreate(
            ['regulation_id' => $regulation->getKey(), 'user_id' => $reader->getKey()],
            ['acknowledged_at' => now(), 'version_id' => $version?->getKey()],
        );
    }
}
