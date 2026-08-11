<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Когда сотрудник убрал разговор из своей переписки.
 *
 * Отметка, а не удаление. У записи два читателя с несовместимыми правами:
 * сотрудник вправе убрать свою переписку с глаз, а автор курса — нет, для него
 * это перечень того, чего в базе знаний не хватает. Удали строку по просьбе
 * первого, и второй лишится сведений о пробеле, которого он ещё не закрыл.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->timestamp('hidden_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->dropColumn('hidden_at');
        });
    }
};
