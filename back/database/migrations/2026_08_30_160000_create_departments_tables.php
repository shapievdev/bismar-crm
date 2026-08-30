<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Структура компании: дерево отделов и люди в них.
 *
 * Отдел знает своего родителя и своё место среди соседей — этого хватает и для
 * рисунка дерева, и для перетаскивания карточек. Корень один: компания
 * целиком, у неё родителя нет, и удалить её нельзя.
 *
 * Человек связан с отделом строкой с ролью: руководитель, заместитель или
 * сотрудник. Ролей в одном отделе у него не бывает двух, а отделов у человека
 * бывает несколько — начальник направления нередко и в шапке компании, и во
 * главе своего отдела.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('name');

            // Пусто только у корня. Удаление отдела не рушит ветку: детей
            // поднимают к деду — см. App\Actions\Structure\DeleteDepartment.
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();

            // Место среди соседей: порядок в структуре осмыслен — сверху те,
            // кто ближе к делу, — и задаётся он перетаскиванием.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['parent_id', 'position']);
        });

        Schema::create('department_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Кем человек числится в этом отделе. Строкой, а не двумя булевыми
            // полями: ролей три, и «руководитель и заместитель разом» — не
            // состояние, а ошибка.
            $table->string('role', 16);
            $table->timestamps();

            // Одна роль на человека в отделе: назначая руководителем того, кто
            // числится сотрудником, роль меняют, а не заводят вторую строку.
            $table->unique(['department_id', 'user_id']);
            $table->index(['user_id']);
        });

        // Корень заводится сразу: дерево без него нечем показать, а завести
        // его через интерфейс значило бы держать там кнопку на один раз в
        // жизни. Название меняется, как у любого отдела.
        DB::table('departments')->insert([
            'name' => 'Компания',
            'parent_id' => null,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('department_members');
        Schema::dropIfExists('departments');
    }
};
