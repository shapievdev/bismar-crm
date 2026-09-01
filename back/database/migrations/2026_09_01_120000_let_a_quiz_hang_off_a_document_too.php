<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Тест теперь висит не только на уроке, но и на документе.
 *
 * Устройство теста от владельца не зависит: вопросы, варианты, попытки, разбор
 * и статистика у них одни и те же — разное только то, что засчитывается сдачей.
 * Поэтому владелец стал полиморфным, а не появилась третья копия всего этого
 * хозяйства рядом с уроковой и новостной (решение 2026-09-01).
 *
 * Имена видов берутся из карты в AppServiceProvider — те же «lesson» и
 * «regulation», под которыми материал лежит в новостных ссылках и в планах
 * обучения.
 *
 * Внешний ключ на урок при этом уходит, а с ним и каскадное удаление теста
 * вместе с уроком: у полиморфной связи его не бывает. Убирает тест теперь сам
 * урок, см. Lesson::booted().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table): void {
            $table->string('quizzable_type')->nullable()->after('id');
            $table->unsignedBigInteger('quizzable_id')->nullable()->after('quizzable_type');
        });

        DB::table('quizzes')->update([
            'quizzable_type' => 'lesson',
            'quizzable_id' => DB::raw('lesson_id'),
        ]);

        Schema::table('quizzes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lesson_id');

            $table->string('quizzable_type')->nullable(false)->change();
            $table->unsignedBigInteger('quizzable_id')->nullable(false)->change();

            // Один тест на урок и один на документ — как и было у lesson_id.
            $table->unique(['quizzable_type', 'quizzable_id']);
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table): void {
            $table->foreignId('lesson_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::table('quizzes')
            ->where('quizzable_type', 'lesson')
            ->update(['lesson_id' => DB::raw('quizzable_id')]);

        // Тесты документов при откате уносить некуда: у прежней таблицы для них
        // места нет вовсе.
        DB::table('quizzes')->whereNull('lesson_id')->delete();

        Schema::table('quizzes', function (Blueprint $table): void {
            $table->dropUnique(['quizzable_type', 'quizzable_id']);
            $table->dropColumn(['quizzable_type', 'quizzable_id']);
            $table->foreignId('lesson_id')->nullable(false)->change();
        });
    }
};
