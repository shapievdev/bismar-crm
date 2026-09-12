<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Версии документа и справочника (решение пользователя 2026-09-12).
 *
 * Одно правило, написанное для разных людей по-разному: «как считается
 * зарплата» у розницы и у офиса — разный текст, разный бланк и разная проверка,
 * но документ один. Заводить под каждую группу свой документ значило бы
 * размножить и название, и категорию, и список ответственных, и ссылки на него
 * — а поправив правило, обойти их все руками.
 *
 * **Общая версия — это сам документ.** Строки здесь описывают только версии
 * сверх неё: у материала без версий всё остаётся как было, и ни один запрос об
 * этой таблице не спрашивает. Читателю, чьи группы ни с одной версией не
 * совпали, открывается общая — она же и ответ на «а как вообще».
 *
 * Что у версии своё: название, круг групп, признак закрытости, статья, файлы и
 * проверка. Что остаётся общим на весь документ: заголовок, категория, слова
 * поиска, ответственные, соседи, частые вопросы, допуск к самому документу — и
 * отметка «ознакомлен». Отметка одна на человека потому, что версия у него
 * одна: ознакомиться с документом значит прочитать свою версию, а не все.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulation_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('regulation_id')->constrained()->cascadeOnDelete();

            // «Для розницы», «Для офиса» — как версия названа в переключателе.
            // Заголовок документа остаётся общим: версия — это не другой
            // документ, а другой его текст.
            $table->string('name');

            /*
             * Закрытая версия видна только своим группам.
             *
             * У открытой группы решают лишь то, кому она откроется первой:
             * посмотреть чужую версию не запрещено — иногда за этим и приходят
             * («а как считают у них»). Закрытая отвечает на другой случай:
             * текст, который посторонним читать незачем.
             */
            $table->boolean('is_private')->default(false);

            // Порядок задаёт автор, и он же решает спор: человек, попавший в
            // две версии сразу, получает первую из них (решение пользователя
            // 2026-09-12). Иначе выбор зависел бы от того, какую завели позже.
            $table->unsignedInteger('position')->default(0);

            // Тот же формат, что у самого документа: документ редактора блоков.
            $table->jsonb('content_json')->nullable();

            $table->timestamps();

            $table->index(['regulation_id', 'position']);
        });

        /*
         * Для кого эта версия.
         *
         * Группами, а не поимённо: версию пишут для отдела или смены, и список
         * фамилий пришлось бы править при каждом выходе человека на новое
         * место. Состав группы читается живьём — как и при допуске в закрытый
         * материал, см. course_member_groups.
         */
        Schema::create('regulation_version_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('regulation_versions')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['version_id', 'group_id']);

            // «Какая версия моя» спрашивается от человека к его группам.
            $table->index('group_id');
        });

        /*
         * Файлы версии. Null — файл общей версии, то есть всё, что приложено
         * сегодня: старые строки менять не пришлось.
         */
        Schema::table('regulation_attachments', function (Blueprint $table): void {
            $table->foreignId('version_id')->nullable()->after('regulation_id')
                ->constrained('regulation_versions')->cascadeOnDelete();

            $table->index(['regulation_id', 'version_id']);
        });

        /*
         * По какой версии человек ознакомился.
         *
         * Не вторая отметка, а пометка на первой: версия у человека одна, и
         * требовать ознакомления со всеми значило бы требовать прочитать чужие
         * правила. Уникальность остаётся прежней — один человек, один документ.
         */
        Schema::table('regulation_acknowledgements', function (Blueprint $table): void {
            $table->foreignId('version_id')->nullable()->after('regulation_id')
                ->constrained('regulation_versions')->nullOnDelete();
        });

        /*
         * Нарезка текста версии — тот же корпус, что и у документа.
         *
         * Колонка рядом с regulation_id, а не вместо него: отбор в поиске идёт
         * по документу (закрытость, раздел, состояние), и соединять ради этого
         * ещё одну таблицу незачем. Версия лишь сужает выбранное до того
         * текста, который этому человеку и предназначен.
         */
        foreach (['lesson_transcripts', 'transcript_segments'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('version_id')->nullable()->after('regulation_id')
                    ->constrained('regulation_versions')->cascadeOnDelete();
            });
        }

        Schema::table('transcript_segments', function (Blueprint $table): void {
            $table->index(['regulation_id', 'version_id']);
        });

        /*
         * Уникальность расшифровки — с оглядкой и на версию.
         *
         * Имена блоков присваиваются в каждом тексте своим счётом, и без этого
         * первый абзац версии считался бы той же расшифровкой, что первый абзац
         * общей, — второй из них молча не сохранился бы.
         */
        DB::statement('DROP INDEX IF EXISTS lesson_transcripts_source_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX lesson_transcripts_source_unique
            ON lesson_transcripts (
                coalesce(lesson_id, 0),
                coalesce(regulation_id, 0),
                coalesce(version_id, 0),
                source_kind,
                coalesce(source_attachment_id, 0),
                coalesce(source_block_id, '')
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS lesson_transcripts_source_unique');

        foreach (['transcript_segments', 'lesson_transcripts'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('version_id');
            });
        }

        Schema::table('regulation_acknowledgements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('version_id');
        });

        Schema::table('regulation_attachments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('version_id');
        });

        Schema::dropIfExists('regulation_version_groups');
        Schema::dropIfExists('regulation_versions');

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
};
