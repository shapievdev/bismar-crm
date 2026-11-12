<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Экзамены: проверка знаний сама по себе, а не при материале.
 *
 * Тест в платформе всегда висел на чём-то — уроке, документе, версии, новости —
 * и наследовал их жизнь: сдал урок — сдал и тест при нём. Экзамену этого мало:
 * он покрывает сразу несколько курсов, у него есть окно сдачи, срок на человека
 * и таймер. Поэтому экзамен — своя вещь, а вопросы при нём — тот же Quiz, каким
 * он был у урока: экзамен становится пятым владельцем теста (morph `exam`), и
 * вся машинерия вопросов, оценки, попыток и очереди проверяющего достаётся ему
 * бесплатно.
 *
 * Планка зачёта и предел попыток намеренно НЕ здесь: они уже живут на строке
 * теста (`quizzes.passing_score`, `quizzes.max_attempts`), и оценка читает их
 * оттуда. Завести копии при экзамене значило бы однажды сравнить попытку не с
 * тем числом. Разница с уроком одна: уроку планку ставит правило (всегда сто),
 * экзамену — автор.
 *
 * Назначения разворачиваются до человека. Назначают отделом или группой, но
 * хранится по строке на каждого: «отдел» — способ выбрать людей, а не субъект
 * сдачи, и человек, переведённый в другой отдел, не теряет назначенного ему
 * экзамена. Тот же принцип, что у адресатов новостей и плана обучения.
 *
 * Окно текущей попытки (`started_at`/`expires_at`) лежит на назначении, а не на
 * попытке: строка попытки появляется в момент сдачи (так устроена оценка), а
 * таймер должен тикать с момента «Начать». Назначение у человека одно, начатая
 * попытка — тоже одна, им по пути.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table): void {
            $table->id();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('status', 16)->default('draft');

            // Окно сдачи. Оба края необязательны: экзамен «с понедельника и
            // навсегда» так же законен, как «только эта неделя».
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();

            // Минуты на попытку. Пусто — экзамен без таймера.
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();

            // Перемешивать ли вопросы при выдаче: сдающие сидят рядом, и
            // одинаковый порядок у соседей — подсказка, которой не просили.
            $table->boolean('shuffle_questions')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Результаты сданного переживают сам экзамен: удаление прячет его
            // из списков, но попытки остаются читаемыми из статистики.
            $table->softDeletes();
        });

        Schema::create('exam_assignments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            // Личный срок. Может быть раньше закрытия окна, позже — не может
            // (следит действие назначения, а не база: правило, а не целостность).
            $table->timestamp('due_at')->nullable();

            // Окно начатой и не отправленной попытки. Пустое — попытка не идёт.
            // `expires_at` пуст и у идущей, когда экзамен без таймера.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // Назначение у человека одно: повторное не вторая обязанность,
            // а правка срока в существующем.
            $table->unique(['exam_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::table('quiz_attempts', function (Blueprint $table): void {
            // Когда человек сел за работу. Только у экзаменов: попытка теста
            // при уроке рождается в момент отправки, и начала у неё нет.
            $table->timestamp('started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->dropColumn('started_at');
        });

        Schema::dropIfExists('exam_assignments');
        Schema::dropIfExists('exams');
    }
};
