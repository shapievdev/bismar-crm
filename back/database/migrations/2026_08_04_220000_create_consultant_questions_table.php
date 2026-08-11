<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал вопросов к консультанту.
 *
 * Пока его не было, о провале узнавали, только наткнувшись на него самому: чего
 * сотрудники спрашивают и на чём консультант молчит, не знал никто. Здесь
 * копится ровно это — и вместе с исходом видно, чинить материал, поиск или
 * модель.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultant_questions', function (Blueprint $table): void {
            $table->id();

            // Автор вопроса переживает своё увольнение только как прочерк:
            // журнал нужен ради вопросов, а не ради людей.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('question');
            $table->text('answer')->nullable();

            $table->string('outcome')->index();

            // Две цифры, которые и разделяют «нет материала» и «модель его не
            // прочитала»: сколько фрагментов нашёл поиск и на сколько из них
            // ответ сослался.
            $table->unsignedSmallInteger('retrieved')->default(0);
            $table->unsignedSmallInteger('cited')->default(0);

            $table->string('model')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_questions');
    }
};
