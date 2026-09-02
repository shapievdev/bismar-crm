<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Файл при уроке или документе может лежать не у нас.
 *
 * Инструкции компании годами живут на Google Диске, и требовать, чтобы их
 * загрузили сюда второй копией, значит завести две правды: поправят там —
 * здесь останется вчерашнее. Поэтому вложение теперь бывает двух родов, и
 * `source` говорит, какого именно.
 *
 * У файла с Диска нет ни корзины, ни ключа объекта — есть его номер у Google,
 * и по нему собирается адрес просмотра. Отсюда `disk` и `path`, которые
 * перестали быть обязательными: у половины строк их просто нет.
 */
return new class extends Migration
{
    /** Таблицы устроены одинаково, и правка у них одна и та же. */
    private const TABLES = ['lesson_attachments', 'regulation_attachments'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                // 'storage' у всего, что уже загружено: до этой правки другого
                // рода вложений не было.
                $table->string('source')->default('storage');

                // Номер файла у Google. Адрес по нему собираем сами — см.
                // App\Support\Lms\GoogleDrive: присланному с экрана адресу
                // здесь не место, иначе в iframe уехало бы что угодно.
                $table->string('external_id')->nullable();
            });

            Schema::table($name, function (Blueprint $table): void {
                $table->string('disk')->nullable()->change();
                $table->string('path')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            // Сначала уходят строки, у которых корзины и ключа нет вовсе:
            // без них колонки не сделать обязательными обратно.
            DB::table($name)->whereNull('path')->delete();

            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['source', 'external_id']);
            });

            Schema::table($name, function (Blueprint $table): void {
                $table->string('disk')->nullable(false)->change();
                $table->string('path')->nullable(false)->change();
            });
        }
    }
};
