<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вопрос, на который отвечают своими словами.
 *
 * У такого вопроса нет вариантов, зато есть эталон — ответ, написанный автором.
 * Проверка сравнивает написанное сотрудником с эталоном по смыслу и ставит балл,
 * если схожесть выше порога (решение пользователя 2026-09-02).
 *
 * Рядом появляется разбор оценки: по каждому вопросу — сколько баллов дано, чем
 * измерена схожесть и какая она вышла. Без этого «не сдано» выглядело бы
 * приговором без объяснения, а сам ответ уже лежит в `answers`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->text('expected_answer')->nullable()->after('type');
        });

        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->json('scores')->nullable()->after('answers');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->dropColumn('expected_answer');
        });

        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->dropColumn('scores');
        });
    }
};
