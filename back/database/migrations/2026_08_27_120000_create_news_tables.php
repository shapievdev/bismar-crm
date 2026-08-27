<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Новости — то, что сотрудник должен прочитать, а не изучить.
 *
 * Устроены как урок: та же статья на блоках, те же вложенные файлы и видео, тот
 * же тест. Таблицы при этом свои, а не общие с базой знаний (решение
 * пользователя 2026-08-27): уроки работают и покрыты тестами, и переводить их
 * на полиморфные связи ради новостей — риск там, где его можно не брать.
 *
 * Отличий от урока три, и они по существу:
 *
 * 1. Новость знает, кому она адресована, — всем или поимённо.
 * 2. Новость помнит, кто с ней ознакомился, и умеет требовать этого.
 * 3. Тест здесь не оценка знаний, а подтверждение: сдал — значит прочитал.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news', function (Blueprint $table): void {
            $table->id();

            // Автор мог уволиться — новость остаётся: она о деле, а не о нём.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();

            // Строка для карточки в ленте. Отдельно от текста, потому что
            // первый абзац статьи нередко начинается с приветствия.
            $table->text('excerpt')->nullable();

            // Тот же формат, что у урока: документ редактора блоков.
            $table->jsonb('content_json')->nullable();

            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();

            // Закреплённое висит наверху ленты, пока его не открепят.
            $table->boolean('is_pinned')->default(false);

            // «Всем» или «выбранным» — второе разворачивается в news_recipients.
            $table->string('audience')->default('everyone');

            // Ознакомление можно требовать; кнопка «ознакомлен» есть всегда.
            $table->boolean('requires_acknowledgement')->default(false);

            $table->timestamps();

            // Мягко, как и курсы: за новостью стоят отметки об ознакомлении, и
            // случайное удаление не должно уносить их с собой.
            $table->softDeletes();

            // Лента читается сверху вниз: закреплённое, потом свежее.
            $table->index(['status', 'is_pinned', 'published_at']);
        });

        // Кому адресована новость, когда она не для всех.
        Schema::create('news_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['news_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('news_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Нажал кнопку или сдал тест. Новость могла обзавестись тестом уже
            // после того, как часть людей подтвердила её кнопкой, — и тогда
            // «как именно» из самой новости уже не вывести.
            $table->string('source')->default('confirmed');

            $table->timestamp('acknowledged_at');
            $table->timestamps();

            // Ознакомился либо да, либо нет: дважды — это не «дважды прочитал».
            $table->unique(['news_id', 'user_id']);
        });

        // Приложенные документы. Сюда же попадают картинки и видео, вставленные
        // в статью: документ хранит их номер, а не адрес, — подписанные ссылки
        // живут минуты, а статья годы.
        Schema::create('news_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_id')->constrained()->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();

            $table->index('news_id');
        });

        Schema::create('news_quizzes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('passing_score')->default(70);

            // Попыток не ограничиваем по умолчанию: цель теста — убедиться, что
            // человек прочитал, а не отсеять его.
            $table->unsignedTinyInteger('max_attempts')->nullable();
            $table->timestamps();
        });

        Schema::create('news_quiz_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_id')->constrained('news_quizzes')->cascadeOnDelete();
            $table->text('text');
            $table->string('type')->default('single');
            $table->unsignedInteger('points')->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['quiz_id', 'position']);
        });

        Schema::create('news_quiz_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->constrained('news_quiz_questions')->cascadeOnDelete();
            $table->text('text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['question_id', 'position']);
        });

        Schema::create('news_quiz_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_id')->constrained('news_quizzes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->boolean('passed')->default(false);

            // Отправленные ответы: тест могли поправить, а показать человеку,
            // что он тогда выбрал, всё равно нужно.
            $table->json('answers');
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index(['quiz_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_quiz_attempts');
        Schema::dropIfExists('news_quiz_options');
        Schema::dropIfExists('news_quiz_questions');
        Schema::dropIfExists('news_quizzes');
        Schema::dropIfExists('news_attachments');
        Schema::dropIfExists('news_acknowledgements');
        Schema::dropIfExists('news_recipients');
        Schema::dropIfExists('news');
    }
};
