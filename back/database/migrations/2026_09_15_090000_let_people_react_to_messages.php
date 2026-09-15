<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отклик на реплику одним знаком: «👍», «✅», «🔥».
 *
 * Нужен не для веселья. В группе на двадцать человек объявление «завтра сверка в
 * десять» собирает двадцать ответов «понял», и разговор, ради которого группа
 * заведена, тонет в них. Отклик говорит то же самое, не занимая ленту, и сразу
 * отвечает на вопрос, кто прочёл и согласился, — поимённо.
 *
 * Один отклик на человека и реплику, как в телеграме без подписки: тем же
 * знаком отклик снимается, другим — заменяется. Поэтому уникальность по паре
 * «реплика и человек», а не по тройке со знаком: она же и не даёт двойному
 * нажатию завести две строки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();

            // Уволенный уходит вместе со своими откликами: подписать их станет
            // нечем, а «кто-то согласился» не значит ничего.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Сам знак, как его прислали. Набор задан на клиенте и проверяется
            // при отправке: держать его таблицей значило бы ходить в неё на
            // каждый щелчок ради списка из десяти строк, который меняется раз в
            // год.
            $table->string('emoji', 16);

            $table->timestamps();

            $table->unique(['message_id', 'user_id']);

            // Отклики читаются пачкой на всю страницу ленты — сорок реплик
            // одним запросом.
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};
