<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Маршрут развития сотрудника: личная карта этапов вместо плана обучения.
 *
 * Главное решение схемы: **маршрут принадлежит человеку, а не назначению**. У
 * каждого сотрудника он один и навсегда — поначалу пустой, — и наполняется
 * двумя путями: руками или наложением шаблона. Назначения как отдельной вещи
 * нет вовсе: нельзя «назначить» то, что и так твоё.
 *
 * Отсюда вторая половина решения: **этап маршрута владеет своим содержимым**.
 * Материалы, встречи, задания и тест лежат при нём, а не читаются с шаблона;
 * шаблон — трафарет, с которого сняли копию, и дальше их судьбы расходятся.
 * Иначе «править руками» означало бы править всем сразу, а это уже шаблон.
 *
 * Связь с трафаретом остаётся родословной (`source_stage_id`): по ней
 * считается рекорд прошлых участников по одному и тому же тесту и сводится
 * статистика «этапы с задержками». Удалить трафарет она не мешает.
 *
 * «Версии шаблона» — это копия кнопкой «Дублировать», а не таблица версий:
 * программу меняют редко и целиком, и две строки в списке читаются яснее, чем
 * дерево ревизий.
 *
 * Тест — тот же Quiz, что у урока, документа и экзамена, и владельцев у него
 * здесь два: этап шаблона (трафарет вопросов) и этап маршрута (настоящий, с
 * попытками). Планка и предел попыток, как и у всех, живут на строке теста.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_templates', function (Blueprint $table): void {
            $table->id();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('status', 16)->default('draft');

            // Сколько дней занимает маршрут — справочное число для карточки;
            // фактический горизонт задают day_offset этапов.
            $table->unsignedSmallInteger('duration_days')->default(7);

            // Как считать «день N»: по календарю или пропуская субботу и
            // воскресенье. Праздники не учитываются — ограничение первой
            // версии, компенсируется ручным переносом этапа.
            $table->string('day_mode', 16)->default('calendar');

            // Можно ли открывать следующий этап, не закрыв предыдущий.
            $table->boolean('allow_ahead')->default(false);

            // За сколько минут напомнить о встрече. На шаблоне, а не общей
            // настройкой: у маршрутов разных должностей ритм встреч разный.
            // Пусто — не напоминать.
            $table->unsignedSmallInteger('meeting_reminder_minutes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('onboarding_stages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('template_id')->constrained('onboarding_templates')->cascadeOnDelete();

            $table->unsignedSmallInteger('position');

            // День маршрута, в который этап открывается: 1 — день старта.
            $table->unsignedSmallInteger('day_offset');

            $table->string('title');
            $table->text('description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['template_id', 'position']);
        });

        // Ссылки этапа на материалы базы знаний. Только курс и документ
        // (morph `course`/`regulation`): у них «изучено» измеримо — курс
        // по записи, документ по ознакомлению и тесту. Ссылку на то, чьё
        // прочтение нечем проверить, нельзя делать требованием.
        Schema::create('onboarding_stage_links', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('stage_id')->constrained('onboarding_stages')->cascadeOnDelete();
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->unsignedSmallInteger('position');

            // Необязательный материал — «почитайте ещё»: в прогресс не входит.
            $table->boolean('is_required')->default(true);

            /*
             * День, к которому материал стоит изучить. Отдельно от дня этапа:
             * этап «Первая неделя» открывается первым днём, а разбор кассы в
             * нём — дело пятого. Ничего не отпирает и не запирает — это срок
             * на виду, по которому человек расставляет свою неделю сам.
             */
            $table->unsignedSmallInteger('day_offset')->nullable();

            $table->timestamps();

            $table->index(['stage_id', 'position']);
            $table->index(['linkable_type', 'linkable_id']);
        });

        // Заготовка встречи. С кем — либо конкретный человек, либо роль
        // `mentor`: наставник у каждого свой, и подставляется он в момент
        // наложения шаблона. Ровно одно из двух — следит действие.
        Schema::create('onboarding_stage_meetings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('stage_id')->constrained('onboarding_stages')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');

            $table->string('topic');
            $table->string('format', 16)->default('offline');

            // Адрес или ссылка на созвон — смотря по формату.
            $table->string('place')->nullable();

            $table->foreignId('with_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('with_role', 16)->nullable();

            $table->timestamps();
        });

        Schema::create('onboarding_stage_tasks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('stage_id')->constrained('onboarding_stages')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');

            $table->text('text');

            // Кто закрывает задание: сам стажёр отметкой или ответственный
            // подтверждением. «Сам» — для «прочитай правила зала», «ответственный»
            // — для «собери первый заказ при наставнике».
            $table->string('confirm_by', 16)->default('self');

            $table->boolean('is_required')->default(true);

            $table->timestamps();
        });

        /*
         * Личный маршрут. Один на человека и навсегда: пустой у того, кому
         * ещё нечего проходить, и он же наполняется, когда дело доходит.
         * Поэтому ни «кем назначен», ни «по какому шаблону» здесь нет —
         * шаблонов в одном маршруте может побывать несколько, и каждый лишь
         * дописал в него свои этапы.
         */
        Schema::create('onboarding_journeys', function (Blueprint $table): void {
            $table->id();

            // Один маршрут на человека — уникальностью, а не уговором.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // День 1. Пусто, пока в маршруте нет ни одного этапа с датой:
            // отсчёт начинается не с заведения карты, а с первого дела.
            $table->date('started_at')->nullable();

            /*
             * Правила счёта дней. Снимаются с шаблона при первом наложении и
             * дальше правятся руками: маршрут живёт дольше любого шаблона.
             */
            $table->string('day_mode', 16)->default('calendar');
            $table->boolean('allow_ahead')->default(false);
            $table->unsignedSmallInteger('meeting_reminder_minutes')->nullable();

            // active / paused / completed. «Прерван» не бывает: маршрут —
            // часть карточки сотрудника, и прекращается он вместе с ней.
            $table->string('status', 16)->default('active');

            // Когда поставили на паузу — чтобы при возобновлении сдвинуть
            // расписание неоткрытых этапов на длительность простоя.
            $table->timestamp('paused_at')->nullable();

            $table->timestamp('completed_at')->nullable();

            /*
             * Выпуск: допуск к продажам, футболка, штрих-код. Три отметки со
             * своими «кем», потому что это три реальных события, а не одно.
             */
            $table->timestamp('access_confirmed_at')->nullable();
            $table->foreignId('access_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('tshirt_issued_at')->nullable();
            $table->foreignId('tshirt_issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('barcode_issued_at')->nullable();
            $table->foreignId('barcode_issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
        });

        /*
         * Этап личного маршрута — со своим содержимым, а не ссылкой на чужое.
         * Заведён руками или снят с трафарета; во втором случае помнит, с
         * какого именно (`source_stage_id`), но живёт дальше сам по себе.
         */
        Schema::create('onboarding_journey_stages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('journey_id')->constrained('onboarding_journeys')->cascadeOnDelete();

            // Откуда снят. Null — этап придуман для этого человека. Родословная
            // нужна двум сводкам: рекорду по тесту и «этапам с задержками».
            $table->foreignId('source_stage_id')->nullable()->constrained('onboarding_stages')->nullOnDelete();

            $table->unsignedSmallInteger('position');

            $table->string('title');
            $table->text('description')->nullable();

            // День маршрута. Пусто — когда дни не считают вовсе.
            $table->unsignedSmallInteger('day_offset')->nullable();

            /*
             * Когда этап открывается. Пусто — даты нет: этап ждёт только
             * очереди (или открыт сразу, если ходить вперёд разрешено).
             * Перенос и досрочное открытие правят это же поле.
             */
            $table->timestamp('opens_at')->nullable();

            $table->timestamp('completed_at')->nullable();

            /*
             * Попытки сверх предела теста. После «тест не сдан» кто-то должен
             * дать дорогу дальше: добавленная попытка не переписывает историю
             * и не трогает предел для остальных стажёров.
             */
            $table->unsignedSmallInteger('extra_attempts')->default(0);

            $table->timestamps();

            $table->unique(['journey_id', 'position']);
            $table->index('source_stage_id');
        });

        // Материалы этапа маршрута — свои, как и всё его содержимое.
        Schema::create('onboarding_journey_stage_links', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('journey_stage_id')->constrained('onboarding_journey_stages')->cascadeOnDelete();
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->unsignedSmallInteger('position');
            $table->boolean('is_required')->default(true);

            // День, к которому материал стоит изучить, — срок на виду.
            $table->unsignedSmallInteger('day_offset')->nullable();

            $table->timestamps();

            $table->index(['journey_stage_id', 'position']);
            $table->index(['linkable_type', 'linkable_id']);
        });

        Schema::create('onboarding_journey_meetings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('journey_stage_id')->constrained('onboarding_journey_stages')->cascadeOnDelete();

            // Родословная: из какой заготовки встреча выросла. Null — встречу
            // добавили стажёру лично, без заготовки.
            $table->foreignId('stage_meeting_id')->nullable()->constrained('onboarding_stage_meetings')->nullOnDelete();

            // Снапшот заготовки: правка шаблона не переписывает уже
            // назначенную встречу.
            $table->string('topic');
            $table->string('format', 16);
            $table->string('place')->nullable();

            $table->foreignId('with_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('scheduled_at')->nullable();

            /*
             * Перенос — не неудача: статуса «сорвана» у встречи нет вовсе.
             * Перенесённая встреча остаётся ожидающей, а счётчик — честная
             * память о том, сколько раз её двигали.
             */
            $table->unsignedSmallInteger('reschedules_count')->default(0);

            // Встреча зачитывается только подтверждением ответственного:
            // «мы встретились» говорит тот, кто проводил.
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();

            // Чтобы напоминание перед встречей не пришло дважды.
            $table->timestamp('reminded_at')->nullable();

            $table->timestamps();

            $table->index('with_user_id');
        });

        Schema::create('onboarding_journey_tasks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('journey_stage_id')->constrained('onboarding_journey_stages')->cascadeOnDelete();
            $table->foreignId('stage_task_id')->nullable()->constrained('onboarding_stage_tasks')->nullOnDelete();

            $table->text('text');
            $table->string('confirm_by', 16);
            $table->boolean('is_required');

            // Стажёр отметил «сделал». У заданий с подтверждением это заявка,
            // у самостоятельных — сразу и зачёт.
            $table->timestamp('submitted_at')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_journey_tasks');
        Schema::dropIfExists('onboarding_journey_meetings');
        Schema::dropIfExists('onboarding_journey_stage_links');
        Schema::dropIfExists('onboarding_journey_stages');
        Schema::dropIfExists('onboarding_journeys');
        Schema::dropIfExists('onboarding_stage_tasks');
        Schema::dropIfExists('onboarding_stage_meetings');
        Schema::dropIfExists('onboarding_stage_links');
        Schema::dropIfExists('onboarding_stages');
        Schema::dropIfExists('onboarding_templates');
    }
};
