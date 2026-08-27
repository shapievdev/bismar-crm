<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Шаг плана обучения перестаёт быть непременно курсом.
 *
 * Регламент назначают так же, как курс, и держать под него вторую колонку
 * значит спрашивать «а если завтра третий вид» на каждом запросе. Связь
 * становится полиморфной.
 *
 * Существующие строки переносятся, а не теряются: план людям уже составляли.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_plan_items', function (Blueprint $table): void {
            $table->string('plannable_type')->nullable()->after('user_id');
            $table->unsignedBigInteger('plannable_id')->nullable()->after('plannable_type');
        });

        // Всё, что уже назначено, — курсы: другого вида до этой миграции не
        // существовало. Пишем короткое имя из карты (см. AppServiceProvider),
        // а не класс: миграция обязана согласоваться с тем, что пишет модель.
        DB::table('learning_plan_items')->update([
            'plannable_type' => 'course',
            'plannable_id' => DB::raw('course_id'),
        ]);

        Schema::table('learning_plan_items', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'course_id']);
            $table->dropIndex(['user_id', 'position']);
            $table->dropConstrainedForeignId('course_id');
        });

        Schema::table('learning_plan_items', function (Blueprint $table): void {
            $table->string('plannable_type')->nullable(false)->change();
            $table->unsignedBigInteger('plannable_id')->nullable(false)->change();

            // Одна и та же вещь стоит в плане либо один раз, либо ни разу.
            $table->unique(['user_id', 'plannable_type', 'plannable_id'], 'learning_plan_items_unique_item');
            $table->index(['user_id', 'position']);
            $table->index(['plannable_type', 'plannable_id']);
        });
    }

    public function down(): void
    {
        Schema::table('learning_plan_items', function (Blueprint $table): void {
            $table->foreignId('course_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        // Назад переезжают только курсы: колонки под регламент здесь нет, и
        // назначенные регламенты этот откат теряет.
        DB::table('learning_plan_items')
            ->where('plannable_type', 'course')
            ->update(['course_id' => DB::raw('plannable_id')]);

        DB::table('learning_plan_items')->where('plannable_type', '!=', 'course')->delete();

        Schema::table('learning_plan_items', function (Blueprint $table): void {
            $table->dropUnique('learning_plan_items_unique_item');
            $table->dropIndex(['plannable_type', 'plannable_id']);
            $table->dropColumn(['plannable_type', 'plannable_id']);

            $table->unsignedBigInteger('course_id')->nullable(false)->change();
            $table->unique(['user_id', 'course_id']);
        });
    }
};
