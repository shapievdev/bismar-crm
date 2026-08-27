<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Regulation;
use App\Models\RegulationAcknowledgement;
use App\Models\User;

/**
 * «Прочитал и понял» — весь прогресс, какой у регламента бывает.
 *
 * Ставится один раз и не снимается. Повторный вызов — не ошибка и не вторая
 * строка: человек мог нажать дважды, а два браузера — одновременно, и
 * уникальный ключ в таблице разрешает этот спор за нас.
 */
final readonly class AcknowledgeRegulation
{
    public function handle(Regulation $regulation, User $reader): RegulationAcknowledgement
    {
        return RegulationAcknowledgement::firstOrCreate(
            ['regulation_id' => $regulation->getKey(), 'user_id' => $reader->getKey()],
            ['acknowledged_at' => now()],
        );
    }
}
