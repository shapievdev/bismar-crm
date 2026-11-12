<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Прощание с планом обучения.
 *
 * Его место занял маршрут адаптации (onboarding_*): карта этапов по дням
 * вместо очереди материалов, запирающей базу знаний. Таблица уходит вместе с
 * назначениями — прогресс людей она не хранила (он живёт в enrollments,
 * ознакомлениях и попытках тестов), так что терять здесь нечего.
 *
 * `down` восстанавливает только схему: назначения — решения людей, и миграции
 * их не воскресить.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('learning_plan_items');
    }

    public function down(): void
    {
        Schema::create('learning_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plannable_type');
            $table->unsignedBigInteger('plannable_id');
            $table->unsignedSmallInteger('position');
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'plannable_type', 'plannable_id'], 'learning_plan_items_unique_item');
            $table->index(['user_id', 'position']);
            $table->index(['plannable_type', 'plannable_id']);
        });
    }
};
