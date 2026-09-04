<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Документы — тоже база знаний.
 *
 * Консультант читал только учебные материалы: корпус поиска был нарезкой
 * уроков, и правила, по которым люди работают, в нём просто не существовали.
 * Сотрудник спрашивал «как оформить возврат», ответ лежал в документе — и
 * получал «в базе знаний об этом ничего нет».
 *
 * Поэтому расшифровка перестаёт быть принадлежностью урока: она принадлежит
 * либо уроку, либо документу. Ровно одному из двух — это и записано условием.
 *
 * Имена таблиц оставлены прежними. Переименование ради точности слова стоило бы
 * правки всего, что их называет, и ни на строку не изменило бы поведения.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_transcripts', function (Blueprint $table): void {
            $table->foreignId('regulation_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::table('transcript_segments', function (Blueprint $table): void {
            // Дублируется из расшифровки по той же причине, что и урок: чтобы
            // поиск не соединял лишнюю таблицу ради того, чьё это.
            $table->foreignId('regulation_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // Урок перестаёт быть обязательным — но не перестаёт быть нужным:
        // хозяин у расшифровки по-прежнему ровно один, и это проверяется.
        Schema::table('lesson_transcripts', function (Blueprint $table): void {
            $table->foreignId('lesson_id')->nullable()->change();
        });

        Schema::table('transcript_segments', function (Blueprint $table): void {
            $table->foreignId('lesson_id')->nullable()->change();
        });

        foreach (['lesson_transcripts', 'transcript_segments'] as $name) {
            DB::statement(sprintf(<<<'SQL'
                ALTER TABLE %1$s
                ADD CONSTRAINT %1$s_owner_check
                CHECK (num_nonnulls(lesson_id, regulation_id) = 1)
            SQL, $name));
        }

        /*
         * Уникальность расшифровки — теперь с оглядкой на хозяина.
         *
         * Прежний индекс считал урок обязательным: у всех расшифровок
         * документа lesson_id пуст, и без coalesce он развалился бы на
         * «сколько угодно расшифровок текста» — два NULL в SQL не равны.
         */
        DB::statement('DROP INDEX IF EXISTS lesson_transcripts_source_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX lesson_transcripts_source_unique
            ON lesson_transcripts (
                coalesce(lesson_id, 0),
                coalesce(regulation_id, 0),
                source_kind,
                coalesce(source_attachment_id, 0),
                coalesce(source_block_id, '')
            )
        SQL);
    }

    public function down(): void
    {
        // Расшифровки документов уходят: без своей колонки они станут ничьими,
        // а урок, к которому их можно было бы приписать, придумать неоткуда.
        DB::table('transcript_segments')->whereNull('lesson_id')->delete();
        DB::table('lesson_transcripts')->whereNull('lesson_id')->delete();

        foreach (['lesson_transcripts', 'transcript_segments'] as $name) {
            DB::statement(sprintf('ALTER TABLE %1$s DROP CONSTRAINT IF EXISTS %1$s_owner_check', $name));
        }

        Schema::table('transcript_segments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('regulation_id');
            $table->foreignId('lesson_id')->nullable(false)->change();
        });

        Schema::table('lesson_transcripts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('regulation_id');
            $table->foreignId('lesson_id')->nullable(false)->change();
        });

        DB::statement('DROP INDEX IF EXISTS lesson_transcripts_source_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX lesson_transcripts_source_unique
            ON lesson_transcripts (
                lesson_id,
                source_kind,
                coalesce(source_attachment_id, 0),
                coalesce(source_block_id, '')
            )
        SQL);
    }
};
