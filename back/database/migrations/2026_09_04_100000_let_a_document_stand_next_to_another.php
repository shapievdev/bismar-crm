<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Рядом по теме» — соседние документы, названные руками.
 *
 * Категория отвечает на вопрос «где это лежит», а соседство — на другой: «что
 * ещё читать про то же самое». Правила о скидке, о конфликте и о браке могут
 * лежать в разных категориях и всё равно нужны читателю все три.
 *
 * Связь взаимная (решение пользователя 2026-09-04): связал один раз — блок
 * появился у обоих. Хранится она двумя строками, а не одной с разбором «в какую
 * сторону смотреть»: так соседей документа берёт обычная сводная таблица по
 * индексу, без объединения двух выборок на каждом чтении. За тем, чтобы пара
 * строк заводилась и снималась вместе, следит LinkRegulations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulation_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('regulation_id')->constrained('regulations')->cascadeOnDelete();
            $table->foreignId('related_id')->constrained('regulations')->cascadeOnDelete();

            // Кто связал: вопрос «почему это здесь» задают тогда, когда автора
            // правки уже не вспомнить.
            $table->foreignId('linked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['regulation_id', 'related_id']);
            $table->index('related_id');
        });

        // Документ не бывает соседом сам себе. Правило стоит и в действии, но
        // здесь оно переживёт любую ошибку в коде, а такая строка вылезла бы на
        // экран читателя ссылкой на страницу, которую он и так открыл.
        DB::statement(<<<'SQL'
            ALTER TABLE regulation_links
            ADD CONSTRAINT regulation_links_not_itself CHECK (regulation_id <> related_id)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('regulation_links');
    }
};
