<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Уроки, разрезанные на фрагменты — единица, которой ищет консультант.
 *
 * Искать целыми уроками не получается. Урок на семь тысяч знаков не помещается
 * в отведённый ему кусок промпта, обрезается по границе — и ответ, стоящий во
 * второй половине текста, до модели просто не доходит: она добросовестно
 * отвечает, что таких сведений нет. Ровно это и происходило с материалами,
 * вставленными целой страницей.
 *
 * Фрагмент — несколько абзацев подряд. Найденный фрагмент уходит в промпт
 * целиком, поэтому длинный урок отдаёт ту свою часть, которая относится к
 * вопросу, а не первую попавшуюся.
 */
return new class extends Migration
{
    private const DOCUMENT = <<<'SQL'
        setweight(to_tsvector('russian', lower(regexp_replace(coalesce(heading, ''), '[«»„“”‘’‚‛…—–]', ' ', 'g') COLLATE "und-x-icu")), 'A')
        || setweight(to_tsvector('russian', lower(regexp_replace(coalesce(content, ''), '[«»„“”‘’‚‛…—–]', ' ', 'g') COLLATE "und-x-icu")), 'B')
    SQL;

    public function up(): void
    {
        Schema::create('lesson_passages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');

            // Заголовок урока лежит копией рядом с текстом: он весит в поиске
            // больше тела, а выражение индекса не может собрать значение из
            // двух таблиц.
            $table->string('heading');
            $table->text('content');

            $table->unique(['lesson_id', 'position']);
        });

        DB::statement(sprintf(
            'CREATE INDEX lesson_passages_search_idx ON lesson_passages USING gin ((%s))',
            self::DOCUMENT,
        ));

        // Поиск по целым урокам больше не выполняется, а индекс продолжал бы
        // пересчитываться при каждом сохранении.
        DB::statement('DROP INDEX IF EXISTS lessons_search_idx');
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_passages');
    }
};
