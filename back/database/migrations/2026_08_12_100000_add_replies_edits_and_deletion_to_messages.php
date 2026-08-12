<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            /*
             * Ответ на конкретную реплику. Ссылка обнуляется, а не уводит
             * сообщение за собой: удалили ту, на которую отвечали, — ответ
             * остаётся сказанным, просто цитировать больше нечего.
             */
            $table->foreignId('reply_to_id')->nullable()->after('user_id')
                ->constrained('messages')->nullOnDelete();

            /*
             * Когда правили. Не флаг: читателю важно, что реплика изменилась
             * уже после того, как он мог её прочесть, и когда именно.
             */
            $table->timestamp('edited_at')->nullable()->after('body');

            /*
             * Удаление мягкое, и это не осторожность ради отката.
             *
             * На удалённую реплику могли ответить, и ответ без неё повисает в
             * воздухе: «да, согласен» — с чем? Строка остаётся, чтобы на её
             * месте показать «сообщение удалено», а текст и вложения из выдачи
             * уходят.
             */
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropColumn(['edited_at', 'deleted_at']);
        });
    }
};