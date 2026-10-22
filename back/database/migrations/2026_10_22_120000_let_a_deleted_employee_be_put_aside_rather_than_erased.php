<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Удаление сотрудника становится мягким.
 *
 * До сих пор оно стирало запись насовсем, а вместе с ней — прошлое. Отчёт о
 * движении персонала считает по датам приёма и увольнения, а не по нынешнему
 * положению, ради одного свойства: августовская численность не должна меняться
 * от того, что кто-то ушёл в декабре. Стёртая запись это свойство и ломала —
 * человек исчезал из августовской численности, из декабрьских увольнений и из
 * среднесписочной, по которой делится текучесть, причём молча и задним числом.
 *
 * Теперь запись остаётся: приложение её не показывает, войти по ней нельзя,
 * предложить человека к выбору негде, — а отчёт о штате считает её по-прежнему
 * (`withTrashed` в App\Support\Staff\StaffReport). Удаление при этом можно
 * отменить, чего о прежнем сказать было нельзя.
 *
 * Телефон и почта при этом освобождаются. Телефон — это логин
 * (см. 2026_09_10_110000_let_the_phone_be_the_login), и номер, оставшийся за
 * удалённой записью, нельзя было бы выдать ни новому сотруднику, ни тому же
 * человеку, вернувшемуся в компанию: форма отвечала бы «номер занят», а кем —
 * не показала бы, потому что удалённого в приложении не видно. Отсюда
 * частичные уникальные индексы: уникальность спрашивается у живых записей.
 */
return new class extends Migration
{
    /** Колонки, по которым находят человека, — и потому уникальные. */
    private const LOGINS = ['phone', 'email'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->softDeletes();

            /*
             * Кто удалил — как и у выброшенного курса
             * (см. 2026_09_03_140000_remember_who_threw_a_course_away): в
             * списке удалённых об этом спрашивают первым делом.
             *
             * Ссылка обнуляется, а не тянет за собой: удаливший однажды и сам
             * может быть удалён, и запись об удалении не должна уходить следом.
             */
            $table->foreignId('deleted_by_id')->nullable()->after('deleted_at')
                ->constrained('users')->nullOnDelete();

            $table->dropUnique(['phone']);
            $table->dropUnique(['email']);
        });

        // Имена те же, что были у сплошных индексов: это они и есть, только
        // спрашивающие уникальность у живых. Правила проверки в формах
        // заведения и правки сотрудника повторяют это условие.
        foreach (self::LOGINS as $column) {
            DB::statement(
                "create unique index users_{$column}_unique on users ({$column}) where deleted_at is null"
            );
        }
    }

    public function down(): void
    {
        foreach (self::LOGINS as $column) {
            DB::statement("drop index if exists users_{$column}_unique");
            $this->releaseSharedValuesOf($column);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('phone');
            $table->unique('email');

            $table->dropConstrainedForeignId('deleted_by_id');

            // Удалённые записи оживают: отличать их больше нечем. Войти по ним
            // всё равно нельзя — удаляют только уволенного.
            $table->dropSoftDeletes();
        });
    }

    /**
     * Освобождает номер и адрес удалённых записей, делящих их с другими.
     *
     * Сплошной уникальный индекс не встанет, пока удалённая запись делит номер
     * с живой, — а поделить его она могла как раз потому, что уникальность
     * спрашивалась у живых. Освобождает удалённая: её номером уже никто не
     * пользуется, а живому придумать новый нельзя — с него входят.
     */
    private function releaseSharedValuesOf(string $column): void
    {
        DB::statement(<<<SQL
            update users u set {$column} = null
            where u.deleted_at is not null
              and u.{$column} is not null
              and exists (
                  select 1 from users o
                  where o.id <> u.id and o.{$column} = u.{$column}
              )
            SQL);
    }
};
