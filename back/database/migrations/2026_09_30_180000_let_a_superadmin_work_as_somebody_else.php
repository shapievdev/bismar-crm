<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Суперадминистратор работает от имени сотрудника, не выходя из себя
 * (решение пользователя 2026-09-30).
 *
 * Нужно это ради одного вопроса — «что видит этот человек»: права, план
 * обучения, закрытые материалы и версии складываются так, что со стороны их не
 * пересчитать. Спрашивать пароль у сотрудника ради этого нельзя.
 *
 * **Журнал обязателен, и он здесь главное.** Пока суперадминистратор работает
 * под чужим именем, всё сделанное записывается на сотрудника: и отметка об
 * ознакомлении, и сообщение в мессенджере, и сданный тест. Без этой таблицы
 * разобрать потом, кто на самом деле это сделал, было бы нечем — а объяснять
 * человеку, откуда у него взялось действие, которого он не совершал, пришлось
 * бы словами.
 *
 * Строка заводится на вход и закрывается на возврат (или на выход из
 * приложения), поэтому по ней видно **окно**, а не только момент: всё, что
 * случилось с этим сотрудником внутри окна, стоит читать с оглядкой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonations', function (Blueprint $table): void {
            $table->id();

            // Кто вошёл. Суперадминистратор — других сюда не пускают, но
            // ограничение живёт в действии, а не в колонке: уровень доступа
            // человек может однажды потерять, а запись остаётся правдой о том,
            // что было.
            $table->foreignId('impersonator_id')->constrained('users')->cascadeOnDelete();

            // Под кем.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Откуда — тем же вопросом задаются при разборе любого спорного
            // действия.
            $table->string('ip', 45)->nullable();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            // «Кто и когда ходил» и «под кем ходили» — два вопроса, с которыми
            // сюда приходят.
            $table->index(['impersonator_id', 'started_at']);
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonations');
    }
};
