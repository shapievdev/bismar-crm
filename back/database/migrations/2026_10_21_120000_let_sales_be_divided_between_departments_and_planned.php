<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Продавцы витрины и планы продаж.
 *
 * Витрина знает, под чьим именем прошла продажа, — и только имя: «ЛКМ
 * Агабекова Света Шагабудиновна». База знаний знает того же человека как
 * «Агабекова Света» и помнит, в каком он отделе. Связать одно с другим нечем:
 * общего идентификатора у 1С и у нас нет, а имена пишут по-разному и с
 * приставками отделов. Поэтому связь заводится руками — по строке на каждое
 * имя из выгрузки.
 *
 * Отдел берётся из структуры компании, а не заводится вторым списком: дерево
 * отделов уже есть, люди в нём стоят, и второй справочник «отделов продаж»
 * разошёлся бы с ним на первой же перестановке.
 *
 * Отдел хранится у продавца, а не выводится из его сотрудника. Так нужно по
 * двум причинам сразу: за кассой не стоит человека вовсе, а продавец,
 * переведённый в другой отдел, не должен задним числом переписывать прошлые
 * продажи — они случились там, где случились.
 *
 * План — месячный и в выручке. Трёх видов, и третий здесь главный: план отдела
 * целиком, личный план продавца и норматив «на любого продавца этого отдела».
 * Последний избавляет от переписывания десяти одинаковых чисел каждый месяц и
 * сам находит новичка, которому личный план ещё не поставили.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_people', function (Blueprint $table): void {
            $table->id();

            // Имя ровно как в витрине: по нему и сходятся факт с планом.
            // Меняться оно не должно — сменившееся приедет новой строкой, и
            // это честно: для отчёта это другой продавец.
            $table->string('external_name')->unique();

            // Оба необязательны: строка заводится и наполовину. Отдел без
            // человека — это касса, человек без отдела — сопоставление, которое
            // начали и не закончили; в отчёте такая выручка стоит
            // нераспределённой, а не теряется.
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index('department_id');
            $table->index('user_id');
        });

        Schema::create('sales_plans', function (Blueprint $table): void {
            $table->id();

            // Первое число месяца. Планы ставят на календарный месяц, и
            // хранить обе границы значило бы позволить им разойтись.
            $table->date('period');

            // На что план: 'department' — на отдел целиком, 'member' — норматив
            // на любого продавца отдела, 'person' — на конкретного.
            $table->string('kind', 16);

            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('sales_person_id')->nullable()->constrained('sales_people')->cascadeOnDelete();

            // Выручка. Решение пользователя 2026-10-21: план ставится в ней
            // одной — прибыль и число заказов в планах не участвуют.
            $table->decimal('revenue', 14, 2);

            $table->timestamps();

            $table->index(['period', 'kind']);
        });

        /*
         * Один план на месяц для каждой цели.
         *
         * Частичными индексами, а не одним общим: Postgres считает строки с
         * NULL различными, и обычный unique по четырём столбцам пропустил бы
         * два плана на один и тот же отдел — у обоих `sales_person_id` пуст.
         * Условие на вид заодно разводит план отдела и норматив, которые
         * отличаются только им.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX sales_plans_department_unique
                ON sales_plans (period, department_id)
                WHERE kind = 'department'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX sales_plans_member_unique
                ON sales_plans (period, department_id)
                WHERE kind = 'member'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX sales_plans_person_unique
                ON sales_plans (period, sales_person_id)
                WHERE kind = 'person'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_plans');
        Schema::dropIfExists('sales_people');
    }
};
