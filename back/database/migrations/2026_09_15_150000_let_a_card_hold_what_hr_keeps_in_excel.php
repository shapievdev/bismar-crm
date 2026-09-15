<?php

declare(strict_types=1);

use App\Enums\EmploymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кадровое в карточке сотрудника: приём, положение, уход, наставник.
 *
 * До сих пор приложение знало о человеке только то, что нужно, чтобы пустить
 * его внутрь: имя, телефон, должность и дату увольнения. Движение персонала —
 * сколько приняли, сколько ушло, кто сколько продержался — считалось сводками
 * в Excel, и потому не считалось почти никогда.
 *
 * Дата приёма — то, на чём стоит весь отчёт: без неё нет ни стажа, ни
 * текучести, ни онбординга. Колонка всё же необязательная, и это осознанно: в
 * базе уже есть люди, заведённые до этого дня, и сделать поле обязательным
 * значило бы либо придумать им дату, либо не дать сохранить карточку. Отчёт
 * называет незаполненных поимённо, а форма приёма спрашивает дату сразу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('hired_at')->nullable()->after('phone');

            /*
             * Положение: работает, в декрете, исполняет обязанности.
             *
             * «Уволен» сюда не кладётся, хотя в отчёте такое положение есть.
             * Увольнение уже записано датой, и второе место, где то же самое
             * сказано словом, однажды разойдётся с первым — а на дату
             * увольнения смотрит половина приложения, от списка коллег до
             * мессенджера. Поэтому здесь только то, чего дата не говорит, а
             * «уволен» выводится из неё (см. App\Enums\EmploymentStatus).
             */
            $table->string('employment_status')->default(EmploymentStatus::Working->value)->after('hired_at');

            /*
             * Почему ушёл — из закрытого списка, а не текстом.
             *
             * Текстом это не сложится в доли: «по собственному», «по
             * собственному желанию» и «сам ушёл» — три разные строки и одна
             * причина, и в отчёте они встанут тремя полосками.
             */
            $table->string('dismissal_reason')->nullable()->after('dismissed_by_id');

            // Смена или офис: у них разный ритм выхода и разная текучесть, и
            // смешивать их в одной цифре значит не увидеть ни той ни другой.
            $table->string('work_mode')->nullable()->after('employment_status');

            /*
             * Кто ведёт новичка. Ссылка обнуляется, а не тянет за собой: ушёл
             * наставник — подопечный остаётся, просто без наставника.
             */
            $table->foreignId('mentor_id')->nullable()->after('work_mode')
                ->constrained('users')->nullOnDelete();

            // Отчёт то и дело спрашивает «кого приняли в этом месяце» и «кто
            // ушёл» — по обеим датам разом.
            $table->index(['hired_at', 'dismissed_at']);
        });

        Schema::table('quizzes', function (Blueprint $table): void {
            /*
             * Аттестация испытательного срока — та, по которой считают
             * онбординг.
             *
             * Отметкой на самой аттестации, а не одной на всю компанию
             * (решение пользователя 2026-09-15): у розницы и у опта проверки
             * разные, а новичок считается прошедшим, сдав ту, что назначена
             * ему.
             */
            $table->boolean('is_probation')->default(false)->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table): void {
            $table->dropColumn('is_probation');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['hired_at', 'dismissed_at']);
            $table->dropConstrainedForeignId('mentor_id');
            $table->dropColumn(['hired_at', 'employment_status', 'work_mode', 'dismissal_reason']);
        });
    }
};
