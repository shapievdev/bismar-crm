<?php

declare(strict_types=1);

use App\Models\Quiz;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Тест при уроке зачитывается только при всех верных ответах.
 *
 * Прежде планку задавал автор (по умолчанию 70), и урок закрывался с тремя
 * ошибками из десяти. Решение пользователя 2026-08-31: у уроков планка одна и
 * равна ста процентам, — поэтому она переписывается у всех тестов и становится
 * значением по умолчанию для новых.
 *
 * Прошлые попытки не пересчитываются: отметку «пройдено», честно полученную по
 * прежнему правилу, у людей не отзывают. Новая планка — про то, что будет
 * дальше. Проверки при новостях это не касается: у них своя таблица и своя
 * планка, там тест подтверждает ознакомление, а не зачитывает урок.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table): void {
            $table->unsignedTinyInteger('passing_score')->default(Quiz::PASSING_SCORE)->change();
        });

        DB::table('quizzes')->update(['passing_score' => Quiz::PASSING_SCORE]);
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table): void {
            $table->unsignedTinyInteger('passing_score')->default(70)->change();
        });
    }
};
