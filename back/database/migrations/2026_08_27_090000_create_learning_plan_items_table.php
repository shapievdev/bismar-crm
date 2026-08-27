<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * План обучения сотрудника — курсы, которые ему назначили пройти, в том
 * порядке, в котором их стоит проходить.
 *
 * Не то же, что запись на курс (`enrollments`): запись заводится сама, стоит
 * человеку открыть урок, и говорит лишь «здесь у него есть прогресс». Строка
 * плана — чужое решение о том, что этому человеку нужно изучить, и она
 * появляется до того, как он что-либо откроет.
 *
 * План личный: у каждого свой набор строк. Общей «программы обучения», которую
 * назначают многим сразу, здесь нет — решение пользователя 2026-08-27.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Курсы удаляются мягко, поэтому каскад здесь срабатывает только
            // на окончательном удалении. Убранный в корзину курс оставляет свой
            // шаг на месте — как и запись на курс, — а из списков он уходит
            // сам: их отбор идёт через связь, а та не видит удалённого.
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            // Порядок внутри плана одного человека. Не уникален намеренно:
            // перестановка списка переписывает номера по очереди, и на середине
            // прохода два шага законно делят один номер.
            $table->unsignedSmallInteger('position');

            // Кто назначил. Спрашивают об этом тогда, когда ответа уже не найти.
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Курс стоит в плане либо один раз, либо ни разу: дважды пройти
            // один и тот же материал — не план, а ошибка составителя.
            $table->unique(['user_id', 'course_id']);

            // Читают план всегда целиком и по порядку.
            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_plan_items');
    }
};
