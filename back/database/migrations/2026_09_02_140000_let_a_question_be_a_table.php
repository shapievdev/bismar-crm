<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вопрос-таблица: месяцы, недели, статьи расходов.
 *
 * Устройство таблицы — столбцы, строки и признак «строки можно добавлять» —
 * лежит одним json рядом с вопросом: это форма, а не ключ, и разносить её по
 * трём таблицам ради двух списков незачем. Ответ сотрудника ложится в те же
 * `quiz_attempts.answers` матрицей строк.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->json('table_definition')->nullable()->after('expected_answer');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_questions', function (Blueprint $table): void {
            $table->dropColumn('table_definition');
        });
    }
};
