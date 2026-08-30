<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Подписки на push-уведомления.
 *
 * Подписка принадлежит устройству, а не человеку: телефон, рабочий компьютер и
 * домашний — три разные строки, и включать уведомления надо на каждом. Поэтому
 * ключ здесь не по человеку, а по адресу доставки, который выдал браузер.
 *
 * Ключи `p256dh` и `auth` браузер отдаёт вместе с адресом: ими шифруется тело
 * уведомления, и без них служба доставки его не примет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Адрес выдаёт браузер, и он длинный — до нескольких сотен знаков.
            // Уникален: тот же браузер, подписавшись заново, обновляет строку,
            // а не заводит вторую.
            $table->text('endpoint');
            $table->string('public_key');
            $table->string('auth_token');

            // Чем подписались — чтобы человек в настройках узнал своё
            // устройство среди прочих.
            $table->string('device')->nullable();

            $table->timestamps();

            $table->unique('endpoint', 'push_subscriptions_endpoint_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
