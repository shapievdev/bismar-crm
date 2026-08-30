<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Телефон и должность сотрудника — оба необязательны.
 *
 * Телефон хранится в одном виде — «+79990009977», без скобок, пробелов и
 * дефисов: разделители у каждого свои, а искать и сверять надо одно и то же
 * число. Красивый вид собирает интерфейс, приводит к этому — App\Support\Phone.
 *
 * Столбец назван `job_title`, а не `position`: «position» в этой базе уже
 * означает порядок шага в плане обучения, и одно слово в двух смыслах читалось
 * бы как ошибка.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // С запасом против двенадцати знаков «+7…»: если однажды понадобится
            // иностранный номер, менять придётся правило, а не таблицу.
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('job_title')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'job_title']);
        });
    }
};
