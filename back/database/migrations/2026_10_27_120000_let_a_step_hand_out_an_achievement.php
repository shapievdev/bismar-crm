<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Достижения: значок с картинкой, который сотрудник получает за пройденный шаг.
 *
 * Две таблицы и десяток колонок — потому что вещи здесь разные. Достижение
 * («Первый выход в зал») — справочник: его заводят один раз и дальше вешают на
 * шаги маршрутов. Выданное достижение — событие: у него есть день и причина, и
 * оно принадлежит человеку, а не шагу, который его выдал.
 *
 * **Достижение прячется, а не стирается.** У тега сотрудника удаление снимает
 * его со всех — тег это ярлык. Здесь наоборот: полученное нельзя отобрать
 * правкой справочника, иначе выкинутое из каталога достижение пропало бы из
 * профилей тех, кто его заслужил. Поэтому мягкое удаление: из выбора исчезает,
 * в профилях остаётся.
 *
 * **Одно достижение у человека одно** (unique). Два разных шага могут вести к
 * одному значку — у сотрудника от этого не появится его копия: засчитывается
 * первый, и выдача повторно ничего не портит. Это же свойство позволяет звать
 * выдачу из ленивого пересчёта маршрута сколько угодно раз.
 *
 * **Чем заслужено — снимком, а не ссылкой.** `source` показывает на строку,
 * которая выдала, но этап могут переписать или убрать, а в профиле должно
 * остаться «За этап «Первая неделя»». Поэтому рядом лежит `reason` — текст на
 * момент выдачи.
 *
 * Колонка `achievement_id` ложится на каждый вид шага — этап, материал,
 * встречу, задание, — и по обе стороны: у трафарета шаблона и у этапа личного
 * маршрута. Повторение здесь не небрежность, а то же решение, что у
 * `is_required` и `day_offset`: этап маршрута владеет своим содержимым, и
 * после копии их судьбы расходятся.
 *
 * Тест этапа — отдельной колонкой `quiz_achievement_id` на самом этапе, а не на
 * строке теста: `quizzes` общая на урок, документ, новость и экзамен, и класть
 * в неё поле, которое нужно одному владельцу из шести, значило бы объявить
 * достижения частью тестов вообще.
 */
return new class extends Migration
{
    /** Шаги, которые умеют выдавать достижение: по колонке на каждый. */
    private const STEP_TABLES = [
        'onboarding_stages',
        'onboarding_journey_stages',
        'onboarding_stage_links',
        'onboarding_journey_stage_links',
        'onboarding_stage_meetings',
        'onboarding_journey_meetings',
        'onboarding_stage_tasks',
        'onboarding_journey_tasks',
    ];

    /** Этапы — им вдобавок достижение за сданный тест. */
    private const STAGE_TABLES = [
        'onboarding_stages',
        'onboarding_journey_stages',
    ];

    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table): void {
            $table->id();

            $table->string('title')->unique();

            // За что оно — строкой для подписи под значком. Необязательно:
            // «Первый выход в зал» объясняет себя сам.
            $table->string('description', 500)->nullable();

            // Картинка в бакете. Пусто — экран рисует заглушку: достижение без
            // загруженного значка остаётся достижением, а не поломкой.
            $table->string('image_path')->nullable();

            // Порядок в каталоге задаёт администратор: значки читаются
            // списком, и алфавит в нём ничего не значит.
            $table->unsignedSmallInteger('position')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('achievement_user', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('achievement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // День получения — он и стоит в профиле. Отдельно от created_at:
            // этап закрывается днём последнего дела, а не днём, когда это
            // заметил ленивый пересчёт, и выдача следует за ним.
            $table->timestamp('awarded_at');

            // Снимок причины: «Этап «Первая неделя»», «Материал «Кассовая
            // дисциплина»». Шаг потом перепишут, а строка останется читаемой.
            $table->string('reason')->nullable();

            // Какая строка выдала. Для разбора «почему оно у него есть» и
            // чтобы повторная выдача узнавала своё; показывает текст выше.
            $table->nullableMorphs('source');

            $table->timestamps();

            // Одно достижение у человека одно: два шага к одному значку не
            // плодят дубль, а повторный пересчёт ничего не портит.
            $table->unique(['achievement_id', 'user_id']);
            $table->index(['user_id', 'awarded_at']);
        });

        foreach (self::STEP_TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('achievement_id')->nullable()->constrained('achievements')->nullOnDelete();
            });
        }

        foreach (self::STAGE_TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('quiz_achievement_id')->nullable()->constrained('achievements')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::STAGE_TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('quiz_achievement_id');
            });
        }

        foreach (self::STEP_TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('achievement_id');
            });
        }

        Schema::dropIfExists('achievement_user');
        Schema::dropIfExists('achievements');
    }
};
