<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Опросник при материале — рядом с тестом, но не вместо него.
 *
 * Тест спрашивает, что человек понял, и у него есть ключ. Опрос спрашивает, что
 * человек думает, и правильного ответа у него нет вовсе — поэтому ни очков, ни
 * планки, ни попыток здесь нет: опрос проходят один раз, второго захода не
 * бывает (решение пользователя 2026-09-21). Мнение, высказанное дважды, — это
 * два разных мнения, и какое из них считать, решить нечем.
 *
 * Пять таблиц вместо трёх — из-за анонимности. У опроса есть галочка «не
 * записывать, кто ответил», и честно исполнить её можно только одним способом:
 * не связывать ответы с человеком вовсе. Но отметку о прохождении при этом
 * терять нельзя — обязательный опрос держит зачёт материала, и без неё его
 * нечем закрыть. Отсюда две таблицы: `survey_responses` — что ответили (у
 * анонимного без человека), `survey_completions` — кто прошёл (всегда). Там же,
 * уникальным ключом, и живёт правило «единожды».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table): void {
            /*
             * Полиморфно и с самого начала — в отличие от тестов, которые
             * пришлось разводить по двум стопкам таблиц: `quizzes` для урока с
             * документом и `news_quizzes` для новости. Опрос устроен одинаково
             * у всех четырёх владельцев, и повторять эту развилку незачем.
             */
            $table->id();

            // Столбцы вручную, а не `morphs()`: тот заводит свой индекс по паре,
            // а ниже по той же паре стоит уникальный ключ — и обычный индекс
            // рядом с ним ничего не добавляет, кроме места.
            $table->string('surveyable_type');
            $table->unsignedBigInteger('surveyable_id');

            $table->string('title');
            $table->text('description')->nullable();

            // Обязательный держит зачёт материала: урок не пройден, документ и
            // справочник не ознакомлены, новость не подтверждена, пока опрос не
            // отправлен (решение пользователя 2026-09-21).
            $table->boolean('is_required')->default(false);

            // Анонимный не записывает, кто что ответил, — см. заголовок файла.
            $table->boolean('is_anonymous')->default(false);

            // Срок приёма ответов. Пусто — опрос открыт, пока стоит материал:
            // у опроса «к документу» конца может и не быть.
            $table->timestamp('closes_at')->nullable();

            // Слово после отправки: «Спасибо, разберём на планёрке». Пусто —
            // приложение скажет своё, короткое.
            $table->text('thanks')->nullable();

            $table->timestamps();

            // Один опрос на материал, как и один тест: второй опрос при том же
            // уроке — это второй вопрос «что вы думаете», и человек не знает, на
            // какой отвечать.
            $table->unique(['surveyable_type', 'surveyable_id']);
        });

        Schema::create('survey_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->string('type')->default('single');

            /*
             * Обязателен ли сам вопрос — не то же, что обязателен ли опрос.
             * Опрос обязателен для материала, вопрос — внутри опроса: «сколько
             * вам лет» можно и пропустить, а «оцените урок» нельзя.
             */
            $table->boolean('is_required')->default(true);

            // Шкала: границы и подписи концов. У прочих видов пусто — форму,
            // которую никто не рисует, полем обещать не надо.
            $table->unsignedTinyInteger('scale_min')->nullable();
            $table->unsignedTinyInteger('scale_max')->nullable();
            $table->string('scale_min_label')->nullable();
            $table->string('scale_max_label')->nullable();

            // «Свой вариант» у выбора: галочка, дописывающая к вариантам поле
            // для ответа своими словами.
            $table->boolean('allows_other')->default(false);

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['survey_id', 'position']);
        });

        Schema::create('survey_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->constrained('survey_questions')->cascadeOnDelete();
            $table->text('text');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['question_id', 'position']);
        });

        Schema::create('survey_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();

            /*
             * Кто ответил — или null, если опрос анонимный.
             *
             * Ответы уволенного остаются и после удаления учётной записи:
             * `nullOnDelete`, а не каскад. Опрос — это мнение компании о
             * материале, и вычитать из него ушедших значит переписывать
             * прошлое.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Отправленное целиком, как оно было: `{"12": {"options": [3]},
            // "13": {"scale": 4}, "14": {"text": "…"}}`. Хранится снимком по
            // той же причине, что и ответы попытки, — вопрос могли переписать
            // после, а сказанное человеком от этого не меняется.
            $table->json('answers');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['survey_id', 'user_id']);
        });

        Schema::create('survey_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();

            /*
             * «Единожды» держится здесь, а не проверкой в коде: два браузера,
             * нажавшие «отправить» одновременно, иначе создали бы два мнения
             * одного человека. Уникальный ключ разрешает этот спор за нас — так
             * же, как он разрешает двойное нажатие «ознакомлен».
             */
            $table->unique(['survey_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_completions');
        Schema::dropIfExists('survey_responses');
        Schema::dropIfExists('survey_options');
        Schema::dropIfExists('survey_questions');
        Schema::dropIfExists('surveys');
    }
};
