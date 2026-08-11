<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Когда сотрудник взялся за курс, а не просто заглянул в него.
 *
 * Запись на курс заводится от одного открытия урока — так прогресс считается
 * сам, без кнопки «записаться», и это правильно. Но «мои материалы» строились
 * по тем же записям, и туда попадало всё, во что человек когда-либо заглянул:
 * список превращался в историю просмотров, где искать начатое бесполезно.
 *
 * Взялся — это либо нажал «Начать обучение», либо прошёл хотя бы один урок.
 * Просмотр остаётся просмотром: прогресс по нему копится, но в списке своих
 * материалов курс не появляется, пока к нему не приступили.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable()->after('enrolled_at');
        });

        // Начатым считаем то, где есть хоть один пройденный урок: отличить
        // прежнее нажатие кнопки от прежнего просмотра уже нечем, а пройденный
        // урок — свидетельство недвусмысленное.
        DB::statement(<<<'SQL'
            UPDATE enrollments
            SET started_at = enrolled_at
            WHERE EXISTS (
                SELECT 1 FROM lesson_completions
                WHERE lesson_completions.enrollment_id = enrollments.id
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropColumn('started_at');
        });
    }
};
