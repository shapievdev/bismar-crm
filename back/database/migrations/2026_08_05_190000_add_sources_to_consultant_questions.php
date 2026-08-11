<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Источники ответа рядом с самим ответом.
 *
 * Журнал заводился ради разбора пробелов в базе и хранил только текст. Теперь
 * из него же читается переписка сотрудника с консультантом, а ответ без ссылок
 * — половина ответа: проверить утверждение по нему нельзя, а именно ради этого
 * ссылки и ставились.
 *
 * Снимком, а не связью с уроками: ссылка должна вести туда, куда вела в день
 * ответа. Урок могли с тех пор переписать, и подставлять его сегодняшнее
 * содержимое под вчерашний ответ — значит показывать то, чего консультант не
 * говорил.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->jsonb('sources')->nullable()->after('answer');
        });
    }

    public function down(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->dropColumn('sources');
        });
    }
};
