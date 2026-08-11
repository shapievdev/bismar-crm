<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Чем искали, когда вопрос был продолжением разговора.
 *
 * Консультант помнит прошлые вопросы сотрудника и достраивает по ним новый:
 * «а сколько это сохнет?» уходит в поиск как «сколько сохнет фасадная краска?».
 * Сам вопрос при этом остаётся тем, что человек написал, — подменять его
 * догадкой модели нельзя ни в переписке, ни в журнале.
 *
 * А вот разбирающему журнал без этой строки нечего понять: он видит вопрос из
 * трёх слов и источники про краску и не знает, откуда они взялись и почему
 * нашлось не то.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->text('searched_as')->nullable()->after('question');
        });
    }

    public function down(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->dropColumn('searched_as');
        });
    }
};
