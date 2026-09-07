<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ключевые слова у курса и документа.
 *
 * Их пишут ради тех, кто ищет не теми словами, какими написан материал:
 * «пересорт» вместо «несоответствие поставки», «касса» вместо «ККМ», название
 * программы вместо её описания. В тексте таких слов может не быть вовсе, и
 * поиск по названию с описанием их не найдёт.
 *
 * Списком в jsonb, а не строкой через запятую и не отдельной таблицей: слово
 * здесь не сущность — у него нет ни своей страницы, ни своих связей, и
 * соединение ради пяти строк текста ничего не даёт. Строку же пришлось бы
 * разбирать при каждом чтении, и «касса, ккм» отличалось бы от «касса,ккм».
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['courses', 'regulations'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->jsonb('keywords')->default('[]');
            });
        }
    }

    public function down(): void
    {
        foreach (['courses', 'regulations'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('keywords');
            });
        }
    }
};
