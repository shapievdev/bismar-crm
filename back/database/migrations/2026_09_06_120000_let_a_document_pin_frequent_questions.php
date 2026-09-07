<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Частые вопросы» — материалы, приколотые к документу списком.
 *
 * Третий список рядом с соседями и ответственными, и он отвечает на свой
 * вопрос. «Рядом по теме» — «что ещё читать про то же самое», а здесь — «с чем
 * к этой странице приходят чаще всего»: нет ценника, не работает касса, нужна
 * футболка. Вопросами они называются на экране, а на деле это ссылки на другие
 * материалы, и заголовок над ними всегда один.
 *
 * Отличий от соседства два, и оба намеренные.
 *
 * Связь **односторонняя**: список ведут у той страницы, с которой приходят, и
 * встречный блок на приколотом материале означал бы, что редактор завёл его, не
 * зная об этом. Отсюда одна строка на пару, а не две.
 *
 * Раздел **не важен** (решение пользователя 2026-09-06): к правилу о кассе
 * прикалывают справку «что делать, если касса не отвечает», и граница разделов
 * тут только мешает — сотруднику нужен ответ, а не раздел, в котором он лежит.
 *
 * Порядок задаётся руками: список читают сверху вниз, и первым в нём стоит то,
 * с чем приходят чаще.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulation_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('regulation_id')->constrained('regulations')->cascadeOnDelete();
            $table->foreignId('linked_id')->constrained('regulations')->cascadeOnDelete();

            // Кто приколол: вопрос «почему это здесь» задают тогда, когда
            // автора правки уже не вспомнить.
            $table->foreignId('pinned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['regulation_id', 'linked_id']);
            $table->index(['regulation_id', 'position']);
        });

        // Сам на себя материал не ссылается: строка вела бы на страницу,
        // которую читатель и так открыл.
        DB::statement(<<<'SQL'
            ALTER TABLE regulation_questions
            ADD CONSTRAINT regulation_questions_not_itself CHECK (regulation_id <> linked_id)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('regulation_questions');
    }
};
