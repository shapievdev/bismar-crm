<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Документы и справочники, приложенные к уроку.
 *
 * Урок объясняет, как делать; правило говорит, как положено, — и в конце урока
 * читателя отправляют к нему: «а теперь то же самое в кассовой дисциплине».
 * До сих пор это делали ссылкой в тексте, и она ничего не знала ни о
 * переименовании материала, ни о том, что он закрыт от этого человека.
 *
 * Не вложение и не «рядом по теме»: файл лежит в самом уроке, а соседство —
 * между документами. Здесь связь односторонняя, из урока к материалу: список
 * ведёт тот, кто пишет урок, и у документа от этого ничего не появляется.
 *
 * Раздел не важен, как и у «частых вопросов»: сотруднику нужен ответ, а не
 * раздел, в котором он лежит.
 *
 * Порядок задаётся руками — список читают сверху вниз.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('regulation_id')->constrained('regulations')->cascadeOnDelete();

            // Кто приложил: вопрос «почему это здесь» задают тогда, когда
            // автора правки уже не вспомнить.
            $table->foreignId('attached_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['lesson_id', 'regulation_id']);
            $table->index(['lesson_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_materials');
    }
};
