<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расшифровка как её вставил автор.
 *
 * Хранились только разобранные куски, и это была ошибка: они производное, а
 * автору нужен оригинал. Без него расшифровку нельзя ни показать целиком, ни
 * поправить — «заменить» открывало пустое поле, и опечатку в одной реплике
 * приходилось лечить, вставляя часовую запись заново.
 *
 * Собрать оригинал обратно из кусков не выйдет: разбор их склеивает и
 * выбрасывает разметку субтитров. Поэтому исходник хранится рядом — он же и
 * единственное, что можно осмысленно править.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_transcripts', function (Blueprint $table): void {
            $table->text('content')->nullable()->after('source_block_id');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_transcripts', function (Blueprint $table): void {
            $table->dropColumn('content');
        });
    }
};
