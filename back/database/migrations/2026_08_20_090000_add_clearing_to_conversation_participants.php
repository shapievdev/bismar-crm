<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Удалить переписку у себя».
 *
 * Своё удаление — не удаление: у собеседника разговор остаётся целиком, и
 * стирать строки ради того, чтобы один из двоих не видел их в списке, нельзя.
 * Поэтому здесь метка времени, а не флаг и не `delete`.
 *
 * Метка отвечает сразу на два вопроса. Что показывать в ленте — сказанное позже
 * неё. И показывать ли переписку вообще: если с тех пор в ней не сказали ни
 * слова, показывать нечего, и в списке её нет. Напишут снова — она вернётся, но
 * уже без прошлого, ровно как её и оставили.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->timestamp('cleared_at')->nullable()->after('left_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->dropColumn('cleared_at');
        });
    }
};
