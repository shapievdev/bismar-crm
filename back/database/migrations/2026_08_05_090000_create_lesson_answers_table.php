<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица «вопрос → ответ → источник», которую автор ведёт при уроке.
 *
 * До неё консультант знал об уроке только то, что сумел выхватить из его текста
 * поиском, и качество ответа целиком зависело от того, насколько удачно абзац
 * совпал с вопросом. Сказать «вот на этот вопрос ответ здесь» автор не мог
 * никак.
 *
 * Здесь он это говорит прямо, и вместе с ответом указывает место: секунду в
 * записи, страницу в файле, блок в тексте. Все такие таблицы вместе — единый
 * индекс, по которому консультант ищет прежде всего; нарезка текста
 * (lesson_passages) остаётся запасным путём для того, что ещё не размечено.
 */
return new class extends Migration
{
    /**
     * Вопрос весит больше ответа: строку находят по тому, что в ней спрошено.
     * Ответ ищется тоже — сотрудник нередко формулирует вопрос его словами
     * («сколько сохнет второй слой»), — но вторым голосом.
     *
     * Обязано совпадать с выражением, которым ищет CuratedAnswers, иначе
     * Postgres этот индекс не возьмёт. Оба собираются из App\Support\Ai\
     * RussianText, здесь развёрнуто вручную: миграция не должна меняться
     * следом за кодом.
     */
    private const DOCUMENT = <<<'SQL'
        setweight(to_tsvector('russian', lower(regexp_replace(coalesce(question, ''), '[«»„“”‘’‚‛…—–]', ' ', 'g') COLLATE "und-x-icu")), 'A')
        || setweight(to_tsvector('russian', lower(regexp_replace(coalesce(answer, ''), '[«»„“”‘’‚‛…—–]', ' ', 'g') COLLATE "und-x-icu")), 'B')
    SQL;

    public function up(): void
    {
        Schema::create('lesson_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');

            $table->text('question');
            $table->text('answer');

            $table->string('source_kind');

            // Файл удалили — ответ от этого не перестал быть верным, потерялась
            // только ссылка на место. Строка обязана это пережить и показать
            // автору, что источник надо переуказать; cascade вместо этого молча
            // унёс бы написанный им ответ.
            $table->foreignId('source_attachment_id')->nullable()
                ->constrained('lesson_attachments')->nullOnDelete();

            $table->unsignedInteger('source_seconds')->nullable();
            $table->unsignedSmallInteger('source_page')->nullable();
            $table->string('source_block_id')->nullable();

            // Два вектора, а не один: оценка строки — лучший из них, потому что
            // вопрос читателя может быть сформулирован и словами вопроса, и
            // словами ответа. Упакованы как base64 от float32 — см. Support\Ai\
            // Vector.
            $table->text('question_embedding')->nullable();
            $table->text('answer_embedding')->nullable();

            // Векторы разных моделей несравнимы: при смене модели старые надо
            // пересчитать, а не молча подмешивать к новым.
            $table->string('embedding_model')->nullable();

            $table->timestamps();

            $table->unique(['lesson_id', 'position']);
            $table->index('embedding_model');
        });

        DB::statement(sprintf(
            'CREATE INDEX lesson_answers_search_idx ON lesson_answers USING gin ((%s))',
            self::DOCUMENT,
        ));

        Schema::table('consultant_questions', function (Blueprint $table): void {
            // Каким путём получен ответ. Пусто — не получен вовсе.
            $table->string('answered_from')->nullable()->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->dropColumn('answered_from');
        });

        Schema::dropIfExists('lesson_answers');
    }
};
