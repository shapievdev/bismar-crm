<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Утром, а не в полночь: напоминание о сроке экзамена должно застать человека
// на работе, где он может сесть и сдать, а не лечь под ночные уведомления.
Schedule::command('exams:remind')->dailyAt('09:00');

// Той же утренней почтой — наступившие этапы адаптации: стажёру и назначенным.
Schedule::command('onboarding:remind')->dailyAt('09:00')->timezone(config('onboarding.timezone'))->withoutOverlapping();

// Напоминание перед встречей: окно задаёт маршрут, точность — четверть часа.
Schedule::command('onboarding:remind-meetings')->everyMinute()->withoutOverlapping();
