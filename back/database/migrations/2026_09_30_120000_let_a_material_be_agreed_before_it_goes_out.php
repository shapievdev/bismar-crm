<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Согласование материала перед публикацией (решение пользователя 2026-09-30).
 *
 * Автор либо выкладывает материал сам, либо отправляет его на согласование
 * одному человеку или нескольким. Согласовали все — материал публикуется сам;
 * кому-то не понравилось — он пишет причину, и круг заканчивается возвратом
 * автору.
 *
 * **Круг — строка, а не колонка на материале.** Состояний у согласования больше
 * одного, и у каждого свой участник со своим ответом и своей причиной; колонка
 * «на согласовании» не сказала бы ни кто согласует, ни что именно не
 * понравилось. Отсюда две таблицы: круг и ответы в нём.
 *
 * **Владелец полиморфный** — документ, справочник и курс сразу, как у теста,
 * опроса и версии. Вторая такая же таблица под курсы потребовала бы второго
 * свода правил, который однажды разошёлся бы с первым.
 *
 * **Кругов у материала много, открытый — один.** Отправили, вернули, исправили,
 * отправили снова: прежние круги остаются историей, и по ним видно, что
 * говорили в прошлый раз. Один открытый обеспечен частичным уникальным
 * индексом, а не проверкой в коде: два «отправить» подряд — обычное дело при
 * двойном нажатии, и между «не нашли открытый» и «создаём» помещается чужой
 * запрос.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_reviews', function (Blueprint $table): void {
            $table->id();

            // Документ, справочник, курс. Имена — из карты морфов
            // (AppServiceProvider), поэтому в базе лежит «regulation», а не имя
            // класса с пространством имён.
            $table->morphs('reviewable');

            // Какой это круг по счёту: возврат заканчивает круг, исправленный
            // материал уходит новым. Номер нужен людям, а не коду, — по нему
            // читают историю: «во второй раз согласовали».
            $table->unsignedInteger('round')->default(1);

            $table->string('status')->default('pending');

            // Кто отправил. Обычно автор, но правит материал и редактор,
            // которого в него впустили, — отправить может он же.
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();

            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['reviewable_type', 'reviewable_id', 'round']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX material_reviews_one_open_per_material
                ON material_reviews (reviewable_type, reviewable_id)
                WHERE status = 'pending'
        SQL);

        Schema::create('material_review_decisions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('review_id')->constrained('material_reviews')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('status')->default('pending');

            // Причина возврата. Обязательна при возврате — это единственное, что
            // говорит автору, что делать дальше; при согласовании необязательна.
            $table->text('comment')->nullable();

            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            // Один человек — один ответ в круге.
            $table->unique(['review_id', 'user_id']);

            // Очередь согласующего: «что ждёт меня» — запрос по этой паре.
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_review_decisions');
        Schema::dropIfExists('material_reviews');
    }
};
