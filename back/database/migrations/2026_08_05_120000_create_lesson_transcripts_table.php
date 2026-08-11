<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Расшифровки — то, что урок на самом деле содержит.
 *
 * До них консультант знал об уроке ровно столько, сколько было набрано текстом.
 * Часовая запись и приложенный СНиП для него не существовали вовсе: файл лежит
 * в хранилище двоичным комком, видео — тем более. Урок, где всё существенное
 * сказано голосом, был для базы знаний пустым.
 *
 * Теперь у каждой единицы содержания — у записи, у каждого файла, у каждого
 * блока статьи — есть текстовая расшифровка. Её не видит читатель: она не часть
 * материала, а его изложение для машины.
 *
 * Расшифровка блока статьи выводится из самого блока, если её не загрузили, —
 * поэтому набранный текст остаётся искомым и после того, как нарезка урока
 * (lesson_passages) уступает место сегментам.
 */
return new class extends Migration
{
    /**
     * Обязано совпадать с выражением, которым ищет KnowledgeBase, иначе
     * Postgres индекс не возьмёт. Оба собираются из App\Support\Ai\RussianText,
     * здесь развёрнуто вручную: миграция не должна меняться следом за кодом.
     */
    private const DOCUMENT = <<<'SQL'
        setweight(to_tsvector('russian', lower(regexp_replace(coalesce(heading, ''), '[«»„“”‘’‚‛…—–]', ' ', 'g') COLLATE "und-x-icu")), 'A')
        || setweight(to_tsvector('russian', lower(regexp_replace(coalesce(content, ''), '[«»„“”‘’‚‛…—–]', ' ', 'g') COLLATE "und-x-icu")), 'B')
    SQL;

    public function up(): void
    {
        Schema::create('lesson_transcripts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();

            $table->string('source_kind');

            // Файл удалили — его расшифровка сама по себе ничего не значит, в
            // отличие от строки таблицы: там оставался написанный человеком
            // ответ, здесь — изложение исчезнувшего документа.
            $table->foreignId('source_attachment_id')->nullable()
                ->constrained('lesson_attachments')->cascadeOnDelete();

            $table->string('source_block_id')->nullable();

            /*
             * Выведена из самого блока статьи, а не загружена автором.
             *
             * Такие пересобираются при каждом сохранении урока; загруженные
             * переживают правку текста и перекрывают выведенную.
             */
            $table->boolean('is_derived')->default(false);

            // Откуда приехала: имя файла и распознанный формат. Нужны, чтобы
            // автор узнал свою расшифровку в списке, не открывая её.
            $table->string('original_name')->nullable();
            $table->string('format')->nullable();

            $table->timestamps();
        });

        /*
         * Одна расшифровка на единицу содержания.
         *
         * Через coalesce, а не по колонкам напрямую: в SQL два NULL не равны
         * друг другу, и обычный уникальный индекс пропустил бы сколько угодно
         * расшифровок видео — у всех них source_attachment_id пуст.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX lesson_transcripts_source_unique
            ON lesson_transcripts (
                lesson_id,
                source_kind,
                coalesce(source_attachment_id, 0),
                coalesce(source_block_id, '')
            )
        SQL);

        Schema::create('transcript_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transcript_id')->constrained('lesson_transcripts')->cascadeOnDelete();

            // Дублируется из расшифровки, чтобы поиск не соединял три таблицы
            // ради того, чей это урок. Расшифровка урок не меняет.
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('position');

            // Заголовок урока копией рядом с текстом: он весит в поиске больше
            // тела, а выражение индекса не может собрать значение из двух
            // таблиц. Та же причина, что была у lesson_passages.
            $table->string('heading');
            $table->text('content');

            // Место внутри источника. У записи — секунда, с которой сказано;
            // у документа — страница, если расшифровка её называет.
            $table->unsignedInteger('starts_at_seconds')->nullable();
            $table->unsignedSmallInteger('page')->nullable();

            $table->text('embedding')->nullable();
            $table->string('embedding_model')->nullable();

            $table->unique(['transcript_id', 'position']);
            $table->index('embedding_model');
        });

        DB::statement(sprintf(
            'CREATE INDEX transcript_segments_search_idx ON transcript_segments USING gin ((%s))',
            self::DOCUMENT,
        ));

        // Нарезка текста урока растворилась в расшифровках: блок статьи без
        // загруженной расшифровки даёт её из собственного текста, и держать для
        // того же самого вторую таблицу больше незачем.
        Schema::dropIfExists('lesson_passages');
    }

    public function down(): void
    {
        Schema::dropIfExists('transcript_segments');
        Schema::dropIfExists('lesson_transcripts');

        Schema::create('lesson_passages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('heading');
            $table->text('content');
            $table->text('embedding')->nullable();
            $table->string('embedding_model')->nullable();
            $table->unique(['lesson_id', 'position']);
        });

        DB::statement(sprintf(
            'CREATE INDEX lesson_passages_search_idx ON lesson_passages USING gin ((%s))',
            self::DOCUMENT,
        ));
    }
};
