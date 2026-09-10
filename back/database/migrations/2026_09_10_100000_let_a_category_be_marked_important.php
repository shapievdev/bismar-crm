<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отметка «важная категория» — та, мимо которой сотруднику проходить нельзя.
 *
 * Флагом на категории, а не отдельным списком: отмечает её тот же, кто её
 * правит, в той же форме, и хранить связь на стороне было бы негде — списку
 * пришлось бы жить в трёх экземплярах, по одному на раздел.
 *
 * Обоим деревьям сразу (учебному и регламентному): разделов, где категории
 * показывают списком, три — курсы, документы, справочники, — и отметка,
 * работающая в одном из них, читалась бы как поломка в двух остальных.
 *
 * Порядок сортировки отметка не меняет: она выделяет строку цветом, а не
 * поднимает её наверх, — иначе выстроенный вручную `position` перестал бы
 * что-либо значить.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'regulation_categories'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->boolean('is_important')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['categories', 'regulation_categories'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('is_important');
            });
        }
    }
};
