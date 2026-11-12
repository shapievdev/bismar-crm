<?php

declare(strict_types=1);

use App\Enums\ConversationKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Уведомления» — переписка сотрудника с приложением.
 *
 * Схему, как и общий чат, не меняет: это обычная переписка нового вида, и всё,
 * что уже умеет мессенджер — непрочитанное, значок на рельсе, поиск, push,
 * живая доставка, — достаётся ей даром. Отдельный раздел «уведомления» означал
 * бы второй счётчик и второе место, куда надо не забыть заглянуть.
 *
 * Владелец ленты записан в `created_by_id`, и уникальный индекс обещает, что
 * она у человека одна: вторая означала бы, что половина уведомлений приходит
 * в ту, которую он не открывает.
 *
 * Строк здесь не заводим: лента появляется с первым уведомлением. Пустые
 * «Уведомления» у каждого, кому ещё ничего не приходило, — строчка, обещающая
 * содержимое, которого нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(sprintf(
            "create unique index conversations_system_unique on conversations (created_by_id) where kind = '%s'",
            ConversationKind::System->value,
        ));
    }

    public function down(): void
    {
        DB::table('conversations')->where('kind', ConversationKind::System->value)->delete();

        DB::statement('drop index if exists conversations_system_unique');
    }
};
