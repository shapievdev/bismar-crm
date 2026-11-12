<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\OnboardingJourney;
use App\Models\User;

/**
 * Маршрут сотрудника — тот, что у него есть, или новый пустой.
 *
 * Строка заводится не при приёме, а при первом обращении: карта у человека
 * есть по определению, и ряд пустых записей для тридцати тысяч сотрудников
 * ничего к этому не прибавил бы. Для всех остальных вопросов пустой маршрут и
 * отсутствующий — одно и то же.
 */
final readonly class OpenJourney
{
    public function handle(User $employee): OnboardingJourney
    {
        return OnboardingJourney::firstOrCreate(['user_id' => $employee->getKey()]);
    }
}
