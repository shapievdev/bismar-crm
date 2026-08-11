<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кого консультант посоветовал спросить, когда ответа не нашлось.
 *
 * Снимком, как источники и как показанное близкое, и по той же причине:
 * ответственные за курс со временем меняются, а сотруднику было сказано
 * написать вот этому человеку. Переписка, прочитанная через месяц, должна
 * показывать совет, который в ней прозвучал, а не сегодняшний список.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->jsonb('experts')->nullable()->after('related');
        });
    }

    public function down(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->dropColumn('experts');
        });
    }
};
