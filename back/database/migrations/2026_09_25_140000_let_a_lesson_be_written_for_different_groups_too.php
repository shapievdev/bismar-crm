<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Версии у урока — то же, что у документа (решение пользователя 2026-09-25).
 *
 * Один урок курса, написанный для разных людей по-разному: у розницы своя
 * запись и свой бланк, у офиса свои. Заводить под каждую группу свой курс
 * значило бы размножить и модули, и порядок уроков, и записи людей на курс — а
 * поправив один урок, обойти их все руками.
 *
 * **Таблица версий становится общей на все материалы.** Прежде она знала только
 * документ (`regulation_versions.regulation_id`), и второй владелец потребовал
 * бы второй такой же таблицы, вторых групп, вторых отделов — и второго свода
 * правил «кому какая версия видна», который однажды разошёлся бы с первым.
 * Поэтому владелец здесь полиморфный, как у теста и опроса: урок, документ и
 * справочник ложатся одной строкой, а третий вид материала добавится именем в
 * карте морфов, а не третьей таблицей.
 *
 * Что у версии урока своё: название, круг групп и отделов, признак закрытости,
 * статья, видео, файлы, тест и опрос. Что остаётся общим: заголовок урока, его
 * место в модуле, приложенные документы, таблица «вопрос — ответ» и отметка о
 * прохождении. Отметка одна на человека потому, что версия у него одна: пройти
 * урок значит пройти свою версию, а не все.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Прежнее имя говорило о документе, а таблица теперь про всякий
         * материал. Переименование, а не новая таблица: внешние ключи
         * расшифровок, кусков текста, файлов и отметок уже смотрят сюда, и
         * Postgres переносит их вместе с именем.
         */
        Schema::rename('regulation_versions', 'material_versions');
        Schema::rename('regulation_version_groups', 'material_version_groups');
        Schema::rename('regulation_version_departments', 'material_version_departments');

        Schema::table('material_versions', function (Blueprint $table): void {
            // Пустыми — чтобы было чем заполнить уже лежащие строки; ниже они
            // становятся обязательными.
            $table->string('versionable_type')->nullable()->after('id');
            $table->unsignedBigInteger('versionable_id')->nullable()->after('versionable_type');
        });

        // Всё, что было, — версии документов и справочников.
        DB::table('material_versions')->update([
            'versionable_type' => 'regulation',
            'versionable_id' => DB::raw('regulation_id'),
        ]);

        /*
         * Имя внешнего ключа переименование таблицы не трогает: он так и
         * остался `regulation_versions_regulation_id_foreign`, и снять его
         * обычным dropConstrainedForeignId нельзя — тот ищет ключ по новому
         * имени таблицы.
         */
        DB::statement('ALTER TABLE material_versions DROP CONSTRAINT IF EXISTS regulation_versions_regulation_id_foreign');

        Schema::table('material_versions', function (Blueprint $table): void {
            $table->dropColumn('regulation_id');

            $table->string('versionable_type')->nullable(false)->change();
            $table->unsignedBigInteger('versionable_id')->nullable(false)->change();

            // Порядок версий читается только внутри одного материала — тем же
            // столбцом, каким его и задал автор.
            $table->index(['versionable_type', 'versionable_id', 'position'], 'material_versions_owner_position_index');
        });

        /*
         * Запись версии — то, чего у документа нет вовсе.
         *
         * Урок чаще всего и есть запись, и версия «для розницы» без своего
         * видео была бы половиной версии: текст свой, а смотрят все одно и то
         * же. Поля те же, что у самого урока (ссылка или загруженный файл), —
         * иначе проигрыватель пришлось бы учить второму виду источника.
         */
        Schema::table('material_versions', function (Blueprint $table): void {
            $table->string('video_url')->nullable();
            $table->string('video_path')->nullable();
            $table->string('video_disk')->nullable();
            $table->string('video_name')->nullable();
            $table->unsignedBigInteger('video_size')->nullable();
        });

        /*
         * Файлы версии урока. Null — файл общего урока, то есть всё, что
         * приложено сегодня: старые строки менять не пришлось.
         */
        Schema::table('lesson_attachments', function (Blueprint $table): void {
            $table->foreignId('version_id')->nullable()->after('lesson_id')
                ->constrained('material_versions')->cascadeOnDelete();

            $table->index(['lesson_id', 'version_id']);
        });

        /*
         * По какой версии человек прошёл урок.
         *
         * Не второе прохождение, а пометка на первом: версия у человека одна, и
         * требовать пройти все значило бы требовать выучить чужие правила.
         * Уникальность остаётся прежней — одна запись на курс, один урок.
         */
        Schema::table('lesson_completions', function (Blueprint $table): void {
            $table->foreignId('version_id')->nullable()->after('lesson_id')
                ->constrained('material_versions')->nullOnDelete();
        });

        /*
         * Имя в карте морфов у версии тоже перестало быть про документ.
         * Строк под ним может не быть вовсе — тест при версии появился недавно,
         * — но молчаливое расхождение имени с картой стоило бы дороже проверки.
         */
        foreach (['quizzes' => 'quizzable_type', 'surveys' => 'surveyable_type'] as $table => $column) {
            DB::table($table)->where($column, 'regulation_version')->update([$column => 'material_version']);
        }
    }

    public function down(): void
    {
        foreach (['quizzes' => 'quizzable_type', 'surveys' => 'surveyable_type'] as $table => $column) {
            DB::table($table)->where($column, 'material_version')->update([$column => 'regulation_version']);
        }

        Schema::table('lesson_completions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('version_id');
        });

        Schema::table('lesson_attachments', function (Blueprint $table): void {
            $table->dropIndex(['lesson_id', 'version_id']);
            $table->dropConstrainedForeignId('version_id');
        });

        Schema::table('material_versions', function (Blueprint $table): void {
            $table->dropColumn(['video_url', 'video_path', 'video_disk', 'video_name', 'video_size']);
        });

        Schema::table('material_versions', function (Blueprint $table): void {
            $table->foreignId('regulation_id')->nullable()->after('id')
                ->constrained('regulations')->cascadeOnDelete();
        });

        DB::table('material_versions')
            ->where('versionable_type', 'regulation')
            ->update(['regulation_id' => DB::raw('versionable_id')]);

        // Версии уроков обратно не переносятся — в прежней таблице им места
        // нет, и оставить их значило бы оставить строки без документа.
        DB::table('material_versions')->whereNull('regulation_id')->delete();

        Schema::table('material_versions', function (Blueprint $table): void {
            $table->dropIndex('material_versions_owner_position_index');
            $table->dropColumn(['versionable_type', 'versionable_id']);
            $table->unsignedBigInteger('regulation_id')->nullable(false)->change();
            $table->index(['regulation_id', 'position']);
        });

        Schema::rename('material_version_departments', 'regulation_version_departments');
        Schema::rename('material_version_groups', 'regulation_version_groups');
        Schema::rename('material_versions', 'regulation_versions');
    }
};
