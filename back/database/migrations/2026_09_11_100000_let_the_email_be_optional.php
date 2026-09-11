<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Почта перестаёт быть обязательной.
 *
 * Логин — телефон (см. 2026_09_10_110000_let_the_phone_be_the_login), и почта с
 * тех пор всего лишь способ связи: у складского работника её может не быть
 * вовсе, а придуманный ради формы адрес хуже пустого — по нему однажды напишут.
 *
 * Уникальный индекс остаётся: адрес, если он записан, указывает на одного
 * человека. Postgres пропускает в уникальный индекс сколько угодно NULL, так
 * что записи без почты друг другу не мешают.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Возврат требует, чтобы почта была у всех, — иначе `NOT NULL` не встанет.
     * Заведённым без неё она достаётся служебной, собранной из идентификатора:
     * выдумать настоящую всё равно нельзя, а откатиться нужно на работающую
     * базу.
     */
    public function down(): void
    {
        DB::table('users')
            ->whereNull('email')
            ->update(['email' => DB::raw("'user-' || id || '@bismar.local'")]);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
        });
    }
};
