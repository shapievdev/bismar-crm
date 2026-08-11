<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кто отвечает за курс — люди, к которым идут с вопросом, оставшимся без ответа.
 *
 * Не то же, что доступ (course_members) и не то же, что авторство. Автор мог
 * собрать материал и уйти в другой отдел; допущенный к приватному курсу просто
 * его читает. Ответственный — тот, кому пишут, когда написанного не хватило, и
 * потому список этот показывают всем, кто курс видит, а не одному автору.
 *
 * Отдельная таблица, а не поле на курсе: отвечают за курс нередко двое — тот,
 * кто знает предмет, и тот, кто знает, как это устроено у нас.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_experts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Кто назначил. Тот же вопрос, что и с доступом: «почему за это
            // отвечаю я» задают тогда, когда ответа уже не найти.
            $table->foreignId('appointed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Отвечает либо да, либо нет: назначить дважды нельзя.
            $table->unique(['course_id', 'user_id']);

            // «За что отвечает этот человек» — со стороны человека тоже спросят.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_experts');
    }
};
