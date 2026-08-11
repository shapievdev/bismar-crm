<?php

declare(strict_types=1);

use App\Enums\CourseVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Курс, который виден не всем.
 *
 * Существующие курсы становятся открытыми: это то, чем они были до сих пор, и
 * закрыть их задним числом значило бы отобрать доступ у тех, кто уже учится.
 *
 * Список доступа — отдельная таблица, а не запись на курс. Записи заводятся
 * сами, от одного открытия урока, и означают «взялся читать»; здесь же решают
 * за человека, а не он сам, — и решение это переживает и отчисление, и
 * повторную запись.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('visibility')->default(CourseVisibility::Public->value)->after('status');

            // Каталог всегда отбирает по обоим сразу: что человеку видно и что
            // из этого готово.
            $table->index(['visibility', 'status']);
        });

        Schema::create('course_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Кто открыл доступ. Автор курса со временем меняется, а вопрос
            // «кто пустил сюда этого человека» задают именно тогда, когда
            // ответа уже не найти.
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Доступ либо есть, либо нет: выдать его дважды нельзя.
            $table->unique(['course_id', 'user_id']);

            // «Что мне доступно» спрашивают на каждый список курсов и на каждый
            // вопрос консультанту — отбор идёт по человеку.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_members');

        Schema::table('courses', function (Blueprint $table): void {
            $table->dropIndex(['visibility', 'status']);
            $table->dropColumn('visibility');
        });
    }
};
