<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Регламенты — правила, по которым работают, рядом с материалами, по которым
 * учатся.
 *
 * Устроены как курс, но без модулей и уроков: регламент сам себе урок — статья
 * на блоках, файлы, приватность и те же три списка людей. Отличий от курса
 * два, и оба следуют из того, что регламент читают, а не проходят:
 *
 * 1. Нет структуры и нет прогресса по частям. Пройден он или нет — решает
 *    отметка «ознакомлен», одна на регламент.
 * 2. Дерево категорий своё (решение пользователя 2026-08-27): в учебных
 *    категориях ищут, чему научиться, в этих — по какому правилу работать.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulation_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('regulation_categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['parent_id', 'position']);
        });

        Schema::create('regulations', function (Blueprint $table): void {
            $table->id();

            // Автор мог уволиться — регламент остаётся: он о деле, а не о нём.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            // Категорию можно упразднить, не унося с собой её содержимое.
            $table->foreignId('category_id')->nullable()->constrained('regulation_categories')->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();

            // Тот же формат, что у урока и новости: документ редактора блоков.
            // Видео и картинки живут в нём вложениями, как у новости, — своего
            // места под запись у регламента нет, ему хватает статьи.
            $table->jsonb('content_json')->nullable();

            // Состояние и приватность — те же перечисления, что у курса:
            // читаются они одинаково, и вторая пара названий для того же
            // самого разошлась бы с первой на первой же правке.
            $table->string('status')->default('draft');
            $table->string('visibility')->default('public');
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            // Мягко, как и курсы: за регламентом стоят отметки об ознакомлении.
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index('category_id');
        });

        // Кого пустили в закрытый регламент, помимо автора.
        Schema::create('regulation_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('regulation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Кто открыл доступ: вопрос «почему он это видит» задают тогда,
            // когда ответа уже не найти.
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['regulation_id', 'user_id']);
            $table->index('user_id');
        });

        // Кому писать, если написанного не хватило.
        Schema::create('regulation_experts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('regulation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['regulation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('regulation_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('regulation_id')->constrained()->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();

            $table->index('regulation_id');
        });

        /*
         * «Ознакомлен» — весь прогресс, какой у регламента бывает.
         *
         * У курса прогресс складывается из пройденных уроков; здесь проходить
         * нечего, и единственный осмысленный вопрос — прочитал человек правило
         * или нет. Эта же строка отвечает за шаг плана обучения.
         */
        Schema::create('regulation_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('regulation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            $table->timestamps();

            $table->unique(['regulation_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulation_acknowledgements');
        Schema::dropIfExists('regulation_attachments');
        Schema::dropIfExists('regulation_experts');
        Schema::dropIfExists('regulation_members');
        Schema::dropIfExists('regulations');
        Schema::dropIfExists('regulation_categories');
    }
};
