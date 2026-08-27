<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Куда сходить после новости: курс, модуль, урок или регламент.
 *
 * Новость сообщает, что правило поменялось; читателю нужно тут же открыть само
 * правило, а не искать его в каталоге. Связь полиморфная — видов уже четыре, и
 * колонка под каждый превратила бы таблицу в анкету с прочерками.
 *
 * Что именно бывает целью, знает карта в AppServiceProvider: в базе стоит
 * короткое имя вида, а не полное имя класса.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_id')->constrained()->cascadeOnDelete();

            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');

            // Порядок, в котором их перечислил автор: первым идёт то, ради
            // чего новость и написана.
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            // Один и тот же материал стоит при новости либо раз, либо ни разу.
            $table->unique(['news_id', 'linkable_type', 'linkable_id'], 'news_links_unique_target');
            $table->index(['news_id', 'position']);
            $table->index(['linkable_type', 'linkable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_links');
    }
};
