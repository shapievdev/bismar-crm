<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Группы сотрудников — списки людей, собранные вручную.
 *
 * Отдел отвечает на вопрос «где человек работает», группа — «кого зовут
 * вместе»: наставники, кассиры всех магазинов, участники запуска. Они не
 * подчинены друг другу и не образуют дерева: группа — плоский список, и
 * человек бывает сразу в нескольких.
 *
 * Прав группа не даёт: это адресат рассылки и новости, а не роль.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table): void {
            $table->id();

            // Имя одно на компанию: две «Наставники» в списке адресатов
            // различить нечем.
            $table->string('name')->unique();

            // Зачем группа собрана. Необязательно: у «Кассиров» название
            // говорит всё само.
            $table->string('description')->nullable();

            $table->timestamps();
        });

        Schema::create('group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Один человек — одна строка в группе: роли внутри у неё нет, и
            // «добавить второй раз» здесь означает ошибку, а не второе место.
            $table->unique(['group_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_members');
        Schema::dropIfExists('groups');
    }
};
