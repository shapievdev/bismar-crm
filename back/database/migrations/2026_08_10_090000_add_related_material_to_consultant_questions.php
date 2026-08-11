<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Материал, показанный рядом с ответом, — тем же снимком, что и источники.
 *
 * Консультант научился отвечать не только «нашёл» и «ничего нет»: то, что не
 * дотянуло до ответа, он предлагает посмотреть. Показанное так — часть того
 * разговора, и переписка, прочитанная завтра, должна содержать его, а не
 * сегодняшнюю выдачу поиска по тому же вопросу: материал за это время могли
 * дописать, и советы к вчерашнему ответу вышли бы другие.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->jsonb('related')->nullable()->after('sources');
        });
    }

    public function down(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->dropColumn('related');
        });
    }
};
