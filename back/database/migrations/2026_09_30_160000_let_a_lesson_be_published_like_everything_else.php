<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * У урока появляется своё «опубликован» (решение пользователя 2026-09-30).
 *
 * Согласовывать урок стало нечем: курс и документ выходят к людям состоянием, а
 * урок был виден всем, кому открыт курс, с первой минуты — недописанный,
 * непроверенный, какой угодно. Согласование без такого состояния было бы
 * украшением: «согласуйте то, что все уже прочитали».
 *
 * **Существующие уроки остаются видимыми.** Их писали и показывали до этого
 * правила, и спрятать их разом значило бы вынуть содержимое из всех идущих
 * курсов — вместе с прогрессом, который люди уже набрали. Дата берётся из
 * `created_at`: «опубликован тогда же, когда заведён» — ровно то, чем оно и
 * было.
 *
 * **Новый урок заводится черновиком.** Так устроены и курс, и документ, и это
 * единственный порядок, при котором согласование что-то значит: пока идёт круг,
 * урока людям не видно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->timestamp('published_at')->nullable()->after('position');

            // По нему отбирают уроки курса на каждом открытии страницы и при
            // каждом подсчёте прогресса.
            $table->index('published_at');
        });

        DB::table('lessons')->update(['published_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropIndex(['published_at']);
            $table->dropColumn('published_at');
        });
    }
};
