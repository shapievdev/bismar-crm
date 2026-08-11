<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Настройки консультанта, которыми управляет суперадминистратор.
 *
 * Раньше модель, адрес и ключ жили только в .env: сменить модель значило зайти
 * на сервер и перезапустить приложение. Здесь одна строка, которую правят из
 * интерфейса; пустое поле означает «взять из .env», поэтому развёртывание без
 * заполненных настроек продолжает работать по-старому.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();

            $table->string('model')->nullable();
            $table->string('base_url')->nullable();

            // Хранится шифрованным (см. каст в модели) и наружу не отдаётся
            // никогда — интерфейс показывает только последние знаки.
            $table->text('api_key')->nullable();

            // Ключ Anthropic ходит в X-Api-Key, ключи большинства прокси — в
            // Authorization: Bearer. Выбор за тем, кто настраивает.
            $table->string('auth_scheme')->default('bearer');

            $table->unsignedSmallInteger('max_tokens')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
