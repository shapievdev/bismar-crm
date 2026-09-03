<?php

declare(strict_types=1);

use App\Enums\AttestationStatus;
use App\Enums\QuizKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тест, который проверяет человек.
 *
 * До сих пор всякий тест зачитывало приложение, и спросить в нём можно было
 * только то, у чего есть мерка: ключ у выбора, эталон у письменного ответа.
 * Работу — заполненную таблицу, расчёт, разбор случая — так не проверить, и
 * зачёт по заполненности был вежливой формой отказа от проверки.
 *
 * Отсюда два вида теста и назначенный проверяющий у второго. У попытки
 * появляется состояние между отправкой и вердиктом: она ждёт человека.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table): void {
            // Обычный — у всего, что заведено до сих пор: эти тесты и правда
            // проверяет приложение.
            $table->string('kind')->default(QuizKind::Standard->value);

            /*
             * Кто проверяет. Один человек, а не список: у работы должен быть
             * адресат, а очередь, за которую отвечают все, не разбирает никто.
             *
             * Уволят проверяющего — тест останется без него (nullOnDelete), и
             * это правильнее, чем удалить тест: работы уже сданы, а нового
             * проверяющего назначит автор.
             */
            $table->foreignId('examiner_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('quiz_attempts', function (Blueprint $table): void {
            // «Оценено приложением» — у всех прежних попыток: другого способа
            // тогда и не было.
            $table->string('review_status')->default(AttestationStatus::Auto->value);

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            // Чем вердикт объяснён. Пустой у зачтённого — там объяснять нечего;
            // у незачтённого это единственное, ради чего стоит открывать
            // страницу второй раз.
            $table->text('review_comment')->nullable();

            // По этому индексу собирается очередь проверяющего: свежие сверху.
            $table->index(['review_status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table): void {
            $table->dropIndex(['review_status', 'completed_at']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_status', 'reviewed_at', 'review_comment']);
        });

        Schema::table('quizzes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('examiner_id');
            $table->dropColumn('kind');
        });
    }
};
