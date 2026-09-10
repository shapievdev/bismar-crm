<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Телефон становится логином: по нему входят вместо почты.
 *
 * Раз по номеру находят человека, он обязан указывать ровно на одного, — отсюда
 * уникальный индекс. Столбец при этом остаётся `nullable`: сотрудников заводили
 * с необязательным телефоном, пустых записей в базе сколько-то есть, и `NOT
 * NULL` здесь означало бы придумать им номера. Postgres пропускает сколько
 * угодно NULL в уникальный индекс, так что старые записи переживут миграцию —
 * но войти их владельцы не смогут, пока администратор не проставит номер.
 *
 * Совпавшие номера миграция не разрешает молча: два человека с одним номером —
 * это два человека, которые войдут в одну учётную запись. Она останавливается и
 * называет их, а разобраться, чей номер настоящий, может только человек.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guardAgainstSharedNumbers();

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone']);
        });
    }

    /**
     * @throws RuntimeException
     */
    private function guardAgainstSharedNumbers(): void
    {
        $shared = DB::table('users')
            ->select('phone')
            ->whereNotNull('phone')
            ->groupBy('phone')
            ->havingRaw('count(*) > 1')
            ->pluck('phone')
            ->all();

        if ($shared === []) {
            return;
        }

        throw new RuntimeException(
            'Один и тот же телефон записан нескольким сотрудникам, а войти по нему '
            .'должен один: '.implode(', ', $shared).'. Разберите номера и повторите миграцию.'
        );
    }
};
