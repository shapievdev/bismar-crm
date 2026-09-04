<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Из каких закрытых документов собран ответ.
 *
 * Журнал вопросов читает автор материала, а не тот, кто спрашивал. Пока
 * консультант читал одни курсы, хватало перечня закрытых курсов; теперь он
 * читает и документы, и закрытый документ утекал бы в журнал тем же пересказом,
 * от которого его закрывали.
 *
 * Отдельной колонкой, а не вперемешку с курсами: документ №3 и курс №3 — разные
 * вещи, и один список номеров означал бы, что где-то их спутают.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->jsonb('private_document_ids')->default('[]')->after('private_course_ids');
        });
    }

    public function down(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->dropColumn('private_document_ids');
        });
    }
};
