<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сообщение, написанное с материала: «в этом уроке не хватило ответа».
 *
 * Снимком, а не связью: карточка над репликой должна показывать то, что было
 * названо в день отправки. Материал переименуют, переложат в другой раздел или
 * выбросят в корзину — разговор от этого не должен становиться нечитаемым, а
 * ссылка вести в пустоту молча.
 *
 * Одной колонкой, а не пятью: у обычной реплики её нет вовсе, и пять пустых
 * полей у каждой строки переписки — это цена, которую платят все ради
 * меньшинства.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->jsonb('about')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('about');
        });
    }
};
