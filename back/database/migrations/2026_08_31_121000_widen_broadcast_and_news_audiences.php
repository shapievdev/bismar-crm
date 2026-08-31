<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Адресаты рассылок и новостей: к людям и отделам прибавляются группы.
 *
 * У рассылки адресат один — «всем», «выбранным», отделу или группе, — потому
 * что она уходит один раз и в историю попадает тем, чем была отправлена.
 *
 * У новости адресаты складываются: она живёт долго, её читают и отмечают
 * ознакомление, и «отделу продаж, группе наставников и ещё двоим» — обычный
 * случай, а не исключение. Отсюда три таблицы вместо одного столбца, а
 * состав отдела и группы читается на каждом обращении: пришедший в отдел
 * завтра увидит адресованное отделу вчера.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_broadcasts', function (Blueprint $table): void {
            // Рядом с department_id и по тем же правилам: заполнен ровно тогда,
            // когда рассылка ушла группе.
            $table->foreignId('group_id')->nullable()->after('department_id')
                ->constrained()->nullOnDelete();
        });

        Schema::create('news_departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['news_id', 'department_id']);
            $table->index('department_id');
        });

        Schema::create('news_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['news_id', 'group_id']);
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_groups');
        Schema::dropIfExists('news_departments');

        Schema::table('push_broadcasts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('group_id');
        });
    }
};
