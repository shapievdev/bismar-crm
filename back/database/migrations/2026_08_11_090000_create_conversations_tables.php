<?php

declare(strict_types=1);

use App\Enums\ConversationKind;
use App\Enums\MessageKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Переписка сотрудников между собой.
 *
 * До сих пор написать коллеге из системы было нельзя вовсе: ответственному за
 * курс сотрудник писал на почту, то есть уходил из приложения и терял всё, что
 * его сюда привело, — вопрос, ответ консультанта, ссылку на урок.
 *
 * Личная переписка и групповая — одна сущность, а не две. Различаются они лишь
 * тем, сколько в них людей и можно ли менять состав; заводить ради этого две
 * таблицы значило бы писать всё дважды — список, непрочитанное, вложения.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('kind')->default(ConversationKind::Direct->value);

            // Только у групп: у личной переписки имя — это имя собеседника, и
            // хранить его значило бы держать вторую копию, которая устареет.
            $table->string('title')->nullable();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Пара людей, свёрнутая в строку: «3:7», меньший номер первым.
             *
             * Без неё двое, написавшие друг другу одновременно, заводят две
             * личные переписки, и половина сообщений оказывается в той, которую
             * собеседник не открыл. Проверкой в приложении это не лечится —
             * между «не нашли» и «создаём» помещается чужой запрос.
             */
            $table->string('direct_key')->nullable()->unique();

            // Когда в переписке в последний раз что-то сказали: по этому полю
            // строится список, и сортировать его подзапросом к сообщениям на
            // каждую загрузку было бы расточительно.
            $table->timestamp('last_message_at')->nullable()->index();

            $table->timestamps();
        });

        Schema::create('conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // До какого места человек дочитал. Отсюда и непрочитанные, и
            // галочки о прочтении у собеседника.
            $table->timestamp('last_read_at')->nullable();

            // Вышедший из группы остаётся строкой: его сообщения никуда не
            // делись, и подписывать их «бывший участник» надо чем-то.
            $table->timestamp('left_at')->nullable();

            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);

            // «Мои переписки» — самый частый запрос во всём мессенджере.
            $table->index(['user_id', 'left_at']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();

            // Автор пропадает вместе с учётной записью, сообщение остаётся:
            // переписка — это разговор двоих, и вычёркивать из него половину
            // реплик, когда человек уволился, нельзя.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind')->default(MessageKind::Text->value);
            $table->text('body')->nullable();

            $table->timestamps();

            // Лента читается кусками от конца: «последние сорок», потом «сорок
            // до такого-то». Ключ в индексе рядом с перепиской — ровно это.
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
