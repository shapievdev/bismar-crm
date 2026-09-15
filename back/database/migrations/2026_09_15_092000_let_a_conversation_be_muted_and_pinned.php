<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Приглушить разговор и поднять его наверх.
 *
 * Обе отметки личные, и потому лежат в строке участия, а не в самой переписке:
 * рабочая группа, которую один держит закреплённой, второму просто мешает, и
 * решать это за обоих нельзя.
 *
 * Приглушение отвечает на «двадцать человек обсуждают обед, а телефон звонит
 * весь час»: разговор остаётся в списке и продолжает считать непрочитанное, но
 * уведомления на устройство по нему не уходят. Это разные вещи — не видеть и не
 * слышать, — и мессенджер, который умеет только первое, заставляет выходить из
 * групп, чтобы работать.
 *
 * Временем, а не флагом: «когда приглушил» однажды понадобится, чтобы отличить
 * молчащий с прошлого года разговор от приглушённого вчера, а стоит это тех же
 * восьми байт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->timestamp('muted_at')->nullable()->after('cleared_at');
            $table->timestamp('pinned_at')->nullable()->after('muted_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->dropColumn(['muted_at', 'pinned_at']);
        });
    }
};
