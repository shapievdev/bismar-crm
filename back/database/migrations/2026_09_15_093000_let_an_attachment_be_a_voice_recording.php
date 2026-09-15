<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Голосовое сообщение.
 *
 * Отдельного вида сообщения для него нет и не нужно: это то же вложение, просто
 * показывается оно не строкой с именем файла, а волной с кнопкой. Заводить ради
 * показа вторую сущность значило бы разделить надвое отправку, удаление,
 * хранилище и права.
 *
 * Волна и длительность приходят от клиента вместе с записью, и это осознанно.
 * Считать их на сервере значит распаковывать звук средствами PHP — то есть
 * тащить ffmpeg в развёртывание ради полоски под кнопкой. Браузер уже держит
 * запись в памяти и уже разобрал её, чтобы нарисовать волну во время записи;
 * взять готовое стоит нуля.
 *
 * Врать этими числами бессмысленно: длительность видна по самой записи, а
 * подделанная волна испортит вид только тому, кто её прислал.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table): void {
            /*
             * Признаком, а не догадкой по типу файла: присланная почтой запись
             * разговора — тоже audio/*, но это документ, и показывать его надо
             * строкой с именем, а не голосовой волной.
             */
            $table->boolean('is_voice')->default(false)->after('size');

            $table->unsignedInteger('duration_ms')->nullable()->after('is_voice');

            /*
             * Волна — десятки чисел от нуля до ста, по одному на столбик.
             *
             * Ровно столько, сколько столбиков рисуется: держать здесь настоящую
             * огибающую на сотни тысяч отсчётов незачем — её всё равно
             * пришлось бы сжимать к ширине пузыря при каждом показе.
             */
            $table->jsonb('waveform')->nullable()->after('duration_ms');
        });
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table): void {
            $table->dropColumn(['is_voice', 'duration_ms', 'waveform']);
        });
    }
};
