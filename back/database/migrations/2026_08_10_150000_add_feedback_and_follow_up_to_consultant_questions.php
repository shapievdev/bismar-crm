<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Оценка сотрудника, его заявка на дополнение и ответ автора на неё.
 *
 * Три шага одного круга, который прежде обрывался на первом. Консультант
 * отвечал, и что вышло из этого ответа, не знал никто: журнал считает удачей
 * всякий ответ со ссылками, даже когда сослался он не на то. Теперь сотрудник
 * говорит, помогло ли; если нет — просит дописать; автор дописывает, и ответ
 * возвращается в тот же разговор, где вопрос был задан.
 *
 * Всё это ложится на строку журнала, а не в отдельную таблицу: заявка не
 * существует отдельно от вопроса, который её вызвал, и разводить их значило бы
 * хранить одно событие двумя записями.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->string('feedback')->nullable()->after('related');
            $table->timestamp('feedback_at')->nullable()->after('feedback');

            // Заявка отдельно от оценки: палец вниз ставят и молча, а заявка —
            // это просьба, на которую кто-то должен ответить, и её видно в
            // журнале первой.
            $table->timestamp('requested_at')->nullable()->index()->after('feedback_at');
            $table->text('request_note')->nullable()->after('requested_at');

            // Ответ автора снимком, а не только ссылкой на строку урока: строку
            // могут переписать или удалить, а сотруднику было сказано то, что
            // было сказано.
            $table->text('resolution')->nullable()->after('request_note');
            $table->foreignId('resolution_lesson_id')->nullable()->after('resolution')
                ->constrained('lessons')->nullOnDelete();
            $table->foreignId('resolved_by_id')->nullable()->after('resolution_lesson_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by_id');

            // Увидел ли сотрудник дополнение: пока не увидел, оно отмечено в
            // переписке как новое.
            $table->timestamp('resolution_seen_at')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('consultant_questions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolution_lesson_id');
            $table->dropConstrainedForeignId('resolved_by_id');

            $table->dropColumn([
                'feedback',
                'feedback_at',
                'requested_at',
                'request_note',
                'resolution',
                'resolved_at',
                'resolution_seen_at',
            ]);
        });
    }
};
