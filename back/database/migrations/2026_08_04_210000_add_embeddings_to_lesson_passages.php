<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Смысловой вектор фрагмента.
 *
 * Поиск по словам не сшивает то, что человек считает одним и тем же: «как
 * подобрать краску» и «Матрица подбора по помещениям» не совпадают ни одной
 * леммой — русский стеммер даёт «подобра» для глагола и «подбор» для
 * существительного, — и материал, написанный ровно про это, не находился.
 *
 * Вектор хранится упакованным в base64 из float32: 512 чисел занимают около
 * двух килобайт против семи в виде JSON, а расшифровка — один unpack.
 * Расширения pgvector в этой базе нет, поэтому близость считается в приложении
 * и только по кандидатам, которых отобрал полнотекстовый поиск.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_passages', function (Blueprint $table): void {
            $table->text('embedding')->nullable();

            // Модель записана рядом с вектором: векторы разных моделей несравнимы,
            // и при смене модели старые надо пересчитать, а не молча смешивать.
            $table->string('embedding_model')->nullable();
        });

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->string('embedding_model')->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_passages', function (Blueprint $table): void {
            $table->dropColumn(['embedding', 'embedding_model']);
        });

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn('embedding_model');
        });
    }
};
