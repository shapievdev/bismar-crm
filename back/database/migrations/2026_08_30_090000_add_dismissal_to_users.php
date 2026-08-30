<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Увольнение: сотрудник в системе остаётся, а платформа для него закрывается.
 *
 * Не удаление и не флаг «активен»: уволенный — это состояние с датой, и дата
 * здесь важнее самого признака. Ею отвечают на вопрос «с какого числа человек
 * больше не работает», а по ней же видно, кем и когда решение принято, — без
 * этого возвращение в строй выглядит так, будто ничего и не было.
 *
 * Написанное уволенным никуда не девается: курсы, сообщения и вопросы к
 * консультанту остаются, и авторство под ними остаётся тоже.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('dismissed_at')->nullable()->after('email_verified_at');

            // Кто уволил. Тот же вопрос, что и с доступом: «почему я не могу
            // войти» задают тогда, когда ответа уже не найти.
            $table->foreignId('dismissed_by_id')->nullable()->after('dismissed_at')
                ->constrained('users')->nullOnDelete();

            // Списки людей отбирают работающих — это их условие отбора.
            $table->index('dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['dismissed_by_id']);
            $table->dropIndex(['dismissed_at']);
            $table->dropColumn(['dismissed_at', 'dismissed_by_id']);
        });
    }
};
