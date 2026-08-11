<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Абзац, из которого взят кусок, — теперь свойство куска, а не расшифровки.
 *
 * Расшифровка текста заводилась на каждый блок статьи, и это была ошибка
 * масштаба: у урока на семьдесят абзацев в списке появлялось семьдесят
 * расшифровок, между которыми автору нечего выбирать — они все «текст урока».
 *
 * Расшифровка текста теперь одна на урок, а точность ссылки никуда не делась:
 * её держит кусок. Он и раньше помнил секунду записи и страницу документа —
 * абзац встаёт в тот же ряд.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcript_segments', function (Blueprint $table): void {
            $table->string('source_block_id')->nullable()->after('page');
        });

        // Выведенные расшифровки пересобираются из текста, поэтому проще их
        // выбросить, чем сводить воедино: `lms:reindex` или первое сохранение
        // урока соберут одну взамен семидесяти. Загруженные автором не
        // трогаем — их не из чего восстановить.
        DB::table('lesson_transcripts')->where('is_derived', true)->delete();
    }

    public function down(): void
    {
        Schema::table('transcript_segments', function (Blueprint $table): void {
            $table->dropColumn('source_block_id');
        });
    }
};
