<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Разговоры с ИИ-аналитиком.
 *
 * Своей таблицей, а не в журнале консультанта: тот отвечает по базе знаний, и
 * у его записи своя половина полей — источники, эксперты, закрытые курсы,
 * отметка «ответ не помог». Здесь другая половина: собранный дашборд. Общая
 * таблица означала бы строку, у которой всегда пусто больше, чем заполнено.
 *
 * Переписка у каждого своя и читается отсюда же — отдельной таблицы «сообщения»
 * нет намеренно: круг разговора здесь неделим, вопрос без ответа и ответ без
 * вопроса смысла не имеют. Так же устроен и консультант.
 *
 * Дашборд лежит описанием, а не цифрами. Цифры живут в ClickHouse и меняются:
 * открыв вчерашний разговор, сотрудник должен увидеть, как дела **сейчас**, а
 * не снимок вчерашнего дня. Пересчитать описание дешевле, чем хранить снимок, —
 * и честнее.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_questions', function (Blueprint $table): void {
            $table->id();

            // Автор переживает своё увольнение прочерком: журнал нужен ради
            // вопросов, а не ради людей. То же решение, что у консультанта.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('question');
            $table->text('answer')->nullable();

            /*
             * Описание дашборда — или пусто, когда вопрос требовал только слов.
             * «Почему маржа упала» панелью не отвечается, и пустая сетка вместо
             * ответа была бы хуже его отсутствия.
             */
            $table->jsonb('dashboard')->nullable();

            $table->string('outcome')->index();

            $table->string('model')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Очистив переписку, сотрудник ждёт разговора с чистого листа, а не
            // продолжения того, что он убрал с глаз. Записи остаются — журнал
            // ведётся ради вопросов.
            $table->timestamp('hidden_at')->nullable();

            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_questions');
    }
};
