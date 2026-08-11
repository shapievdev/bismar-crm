<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Из каких закрытых курсов собран ответ.
 *
 * Журнал вопросов читает автор материала, а не тот, кто спрашивал: это перечень
 * того, чего базе знаний не хватает. Ответ, собранный из чужого приватного
 * курса, выдал бы его этим же пересказом — от которого курс и закрывали.
 *
 * Списком, а не признаком «приватный»: доступ к каждому курсу свой, и решать по
 * одному биту пришлось бы в пользу самого закрытого — то есть прятать от автора
 * его же материал, стоило ответу зацепить чужой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            // Пустой массив, а не null: «ни одного закрытого курса» — это
            // ответ, а не отсутствие ответа, и запрос отбора проще.
            $table->jsonb('private_course_ids')->default('[]')->after('sources');
        });
    }

    public function down(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->dropColumn('private_course_ids');
        });
    }
};
