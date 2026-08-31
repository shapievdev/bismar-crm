<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Рассылки уведомлений: что и кому отправили.
 *
 * История нужна не для отчётности, а чтобы было видно, кто разбудил компанию в
 * полночь и с каким текстом: уведомление, в отличие от новости, нельзя открыть
 * заново и перечитать — оно исчезает с экрана вместе с прочтением.
 *
 * Числа адресатов и устройств пишутся снимком, а не считаются заново: и люди, и
 * их подписки меняются, а рассылка ушла тогдашним.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_broadcasts', function (Blueprint $table): void {
            $table->id();

            // Автора могли удалить из системы; сказанное остаётся.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->string('body', 500);

            // Куда открыть приложение по нажатию. Пусто — на главную.
            $table->string('url')->nullable();

            $table->string('audience', 16);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('devices_count')->default(0);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index('sent_at');
        });

        Schema::create('push_broadcast_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('push_broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unique(['push_broadcast_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_broadcast_recipients');
        Schema::dropIfExists('push_broadcasts');
    }
};
