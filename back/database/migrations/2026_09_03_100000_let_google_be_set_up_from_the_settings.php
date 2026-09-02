<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ключи Google — в настройках, а не в переменных окружения.
 *
 * Заводит их тот, кто настраивает компанию, а не тот, у кого есть доступ к
 * серверу: это разные люди, и до сих пор второй был нужен ради двух строк.
 *
 * Строка здесь одна на всю систему — как и у настроек консультанта. Её
 * отсутствие означает «взять из .env».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_settings', function (Blueprint $table): void {
            $table->id();

            /*
             * Оба значения хранятся как есть, без шифрования, и это не
             * недосмотр: они публичны по устройству. Окно выбора файла
             * открывается в браузере сотрудника, значит и номер приложения, и
             * ключ уезжают в браузер — прочитать их может всякий, кто откроет
             * исходники страницы. Защищает их не тайна, а список разрешённых
             * источников в Google Cloud: с чужого домена они не работают.
             */
            $table->string('client_id')->nullable();
            $table->string('api_key')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_settings');
    }
};
