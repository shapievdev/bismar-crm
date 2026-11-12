<?php

declare(strict_types=1);

use App\Enums\ConversationKind;
use App\Support\Chat\GeneralChat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Общий чат компании: одна переписка, в которой все и в которой пишут все.
 *
 * Схему он не меняет вовсе — это обычная переписка нового вида. Меняется
 * только одно обещание: такая переписка в базе ровно одна. Держит его
 * частичный уникальный индекс, а не проверка в приложении: «нашли — иначе
 * создаём» в мессенджере разъезжается на первом же одновременном запросе,
 * ровно как разъезжалась бы личная переписка без `direct_key`.
 *
 * Состав материализуется строками участия — по одной на работающего. Так
 * список переписок, непрочитанное, поиск и проверка канала продолжают видеть
 * обычную группу и не обзаводятся оговоркой «кроме общего». Дальше состав
 * ведёт приложение, см. App\Support\Chat\GeneralChat.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(sprintf(
            "create unique index conversations_general_unique on conversations ((kind)) where kind = '%s'",
            ConversationKind::General->value,
        ));

        // Заводим её сразу и вносим всех, кто уже работает: иначе общий чат
        // появился бы у первого, кто откроет мессенджер, а у остальных — нет.
        app(GeneralChat::class)->includeEveryone();
    }

    public function down(): void
    {
        DB::table('conversations')->where('kind', ConversationKind::General->value)->delete();

        DB::statement('drop index if exists conversations_general_unique');
    }
};
