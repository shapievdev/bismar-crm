<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отдел рядом с группой — и в допуске, и в версиях (решение пользователя
 * 2026-09-12).
 *
 * Группа — список, собранный руками под случай; отдел — место в структуре
 * компании. Просьба «открыть складу» отвечает на отдел, и собирать под неё
 * группу-двойник значило бы вести один и тот же список дважды: пришедшего на
 * склад завтра внесут в структуру, а в группу забудут.
 *
 * Адресуясь отделу, адресуются и всему, что под ним, — то же правило, что у
 * новостей и рассылок (App\Support\Structure\DepartmentReach). Поэтому строка
 * здесь одна на отдел, а подотделы разворачиваются при проверке: появившийся
 * завтра подотдел получает доступ сам, без правки списков.
 *
 * Отдельными таблицами, а не полиморфной «аудиторией»: так же устроены адресаты
 * новостей (news_departments и news_groups), и внешние ключи остаются
 * настоящими — удалённый отдел уносит свои строки каскадом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_member_departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();

            // Кто открыл доступ — тот же вопрос, что и у поимённого списка.
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['course_id', 'department_id']);

            // «Что мне доступно» спрашивают от человека к его отделам и тем,
            // что стоят над ними.
            $table->index('department_id');
        });

        Schema::create('regulation_member_departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('regulation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['regulation_id', 'department_id']);
            $table->index('department_id');
        });

        /*
         * Для кого версия — отделом.
         *
         * У открытой версии отдел решает лишь то, кому она откроется первой; у
         * закрытой — ещё и то, кому она вообще видна. То же самое, что у групп,
         * см. regulation_version_groups.
         */
        Schema::create('regulation_version_departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('regulation_versions')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['version_id', 'department_id']);
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulation_version_departments');
        Schema::dropIfExists('regulation_member_departments');
        Schema::dropIfExists('course_member_departments');
    }
};
