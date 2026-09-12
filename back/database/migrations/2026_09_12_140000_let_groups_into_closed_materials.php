<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Допуск в закрытый материал — не только поимённо, но и группой (решение
 * пользователя 2026-09-12).
 *
 * Поимённый список остаётся как был: два способа складываются, а не заменяют
 * друг друга — «весь отдел продаж плюс Иванов из соседнего» встречается чаще,
 * чем каждый из них по отдельности.
 *
 * Состав группы не замораживается: правило доступа читает `group_members` на
 * каждом обращении, поэтому пришедший в группу завтра откроет курс, впущенный
 * вчера, а ушедший из неё — потеряет. Ровно ради этого группу и называют вместо
 * двадцати фамилий; так же устроены и адресаты новостей (news_groups).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_member_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();

            // Кто открыл доступ — тот же вопрос, что и у поимённого списка, и
            // задают его тогда, когда ответа уже не найти.
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Доступ либо есть, либо нет: впустить группу дважды нельзя.
            $table->unique(['course_id', 'group_id']);

            // «Что мне доступно» спрашивают на каждый список курсов и на каждый
            // вопрос консультанту: отбор идёт от человека к его группам.
            $table->index('group_id');
        });

        Schema::create('regulation_member_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('regulation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['regulation_id', 'group_id']);
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_member_groups');
        Schema::dropIfExists('regulation_member_groups');
    }
};
