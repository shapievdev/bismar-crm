<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кто выбросил курс или документ.
 *
 * Удаление и до сих пор было мягким — строка оставалась с отметкой времени, —
 * но добраться до неё можно было только запросом в базу. Теперь удалённое видно
 * в корзине, и первый вопрос у всякого, кто её открывает, один: чьих рук дело.
 * Даты без имени для этого мало.
 *
 * Уволят удалившего — останется дата (nullOnDelete): курс от этого не
 * становится неудалённым.
 */
return new class extends Migration
{
    /** Обе сущности удаляются мягко и попадают в одну корзину. */
    private const TABLES = ['courses', 'regulations'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('deleted_by');
            });
        }
    }
};
