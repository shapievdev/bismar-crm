<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Теги сотрудников, журнал выгрузок и внешние ссылки на отчёт.
 *
 * Теги здесь только ручные. Стаж тегами не хранится: «стажёр», «новичок», «в
 * команде», «опытный», «старожил» полностью выводятся из даты приёма и
 * пройденной аттестации, и хранить их значило бы держать ежедневный пересчёт
 * ради того, что считается на лету за миллисекунду, — а между пересчётами
 * показывать вчерашнюю правду. Кто есть кто, знает App\Enums\TenureTag.
 *
 * Журнал выгрузок — требование 152-ФЗ в переводе на таблицу: списки с ФИО
 * покидают систему, и должно остаться, кто и когда их вынес.
 *
 * Внешняя ссылка — способ показать цифры тому, у кого нет учётной записи, не
 * показав ни одной фамилии. Поэтому в ней нет ни выборки, ни людей: только срез,
 * по которому отчёт пересчитывается заново на каждое открытие.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();

            // Порядок задаёт кадровик: теги читаются списком, и алфавит в нём
            // ничего не значит.
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();
        });

        Schema::create('staff_tag_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Кто повесил. Уволился — тег остаётся: он про того, на ком висит.
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['staff_tag_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('staff_report_exports', function (Blueprint $table): void {
            $table->id();

            // Кто выгружал. Запись остаётся и после его увольнения: журнал
            // отвечает на вопрос «кто вынес список», а не «кто у нас работает».
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('format', 8);

            // Срез, в котором выгружали: без него в журнале стоит «выгрузил
            // список», и какой именно — неизвестно.
            $table->jsonb('filters');

            $table->unsignedInteger('rows')->default(0);

            /*
             * Были ли в выгрузке фамилии.
             *
             * Ради этого журнал и заведён: сводка по подразделениям — обычный
             * отчёт, а список людей — персональные данные, и спрос с них разный.
             */
            $table->boolean('with_names')->default(false);

            $table->timestamps();

            $table->index(['created_at', 'with_names']);
        });

        Schema::create('staff_report_links', function (Blueprint $table): void {
            $table->id();

            // Сам токен — не адрес и не подпись: случайная строка, по которой
            // ссылку и находят. Хранится как есть: показать её надо ещё раз,
            // когда человек потерял письмо.
            $table->string('token', 64)->unique();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            // Срез, который увидит открывший. Людей в нём нет и быть не может:
            // ссылка отдаёт только агрегаты.
            $table->jsonb('filters');

            /*
             * Срок. Обязателен намеренно: ссылка без срока — это ссылка
             * навсегда, а она уходит наружу и живёт в переписках дольше, чем
             * повод, ради которого её выдали.
             */
            $table->timestamp('expires_at');

            // Отозвана раньше срока.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_report_links');
        Schema::dropIfExists('staff_report_exports');
        Schema::dropIfExists('staff_tag_user');
        Schema::dropIfExists('staff_tags');
    }
};
