<?php

declare(strict_types=1);

use App\Support\Ai\RussianText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Поиск по сказанному.
 *
 * Переписка растёт и не убывает: через год в рабочей группе лежат десятки тысяч
 * реплик, и «где-то тут была ссылка на бланк» решается прокруткой на полчаса.
 * Поиск — то, ради чего историю вообще хранят.
 *
 * Русский словарь, а не подстрока: спрашивают «бланк», а написано «бланки» и
 * «бланком», и совпадений по буквам не будет. Выражение то же, что у поиска по
 * урокам (см. RussianText), и по той же причине: база создана в коллации C, где
 * заглавная кириллица не сворачивается и до стеммера не доходит.
 *
 * Индекс обязан повторять выражение запроса слово в слово, иначе Postgres его
 * не возьмёт и пойдёт читать таблицу целиком — а на десятках тысяч реплик это
 * видно глазом.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Одна колонка, без весов: у реплики нет заголовка, и взвешивать нечего
        // относительно чего.
        DB::statement(sprintf(
            "CREATE INDEX messages_search_idx ON messages USING gin ((to_tsvector('russian', %s)))",
            RussianText::normalised('body'),
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_search_idx');
    }
};
