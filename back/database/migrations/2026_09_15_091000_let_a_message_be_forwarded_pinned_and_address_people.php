<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Три вещи, которых реплике не хватало: пересылка, закрепление и адресат.
 *
 * Переслать — единственный честный способ передать сказанное дословно. Прежде
 * его копировали руками, теряя и автора, и день, когда это было сказано; в
 * разговоре о том, кто что обещал, это и есть самое важное.
 *
 * Закрепить — вынести из ленты наверх то, к чему возвращаются: ссылку на
 * регламент, время сверки, состав смены. В группе, где за день сотня реплик,
 * иначе это ищут прокруткой.
 *
 * Упомянуть — позвать человека по имени в общем разговоре. Без этого в группе
 * на двадцать человек вопрос «а ты посмотрел?» адресован всем и потому никому.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            /*
             * Откуда переслано — снимком, как и материал в `about`, и по той же
             * причине: исходную реплику могут удалить, автора уволить, а
             * группу, из которой её взяли, стереть целиком. Подпись «Переслано
             * от Иванова» обязана пережить всё это — иначе пересылка вырождается
             * в анонимную цитату.
             *
             * Связью это быть и не может: пересылают между разговорами, и ссылка
             * на реплику из чужой переписки означала бы, что по ней однажды
             * попробуют показать её содержимое.
             */
            $table->jsonb('forwarded')->nullable()->after('about');

            /*
             * Когда закрепили и кто. Не флагом: закреплённых бывает несколько, и
             * показывают их в порядке закрепления — последнее закреплённое
             * первым, как в телеграме.
             */
            $table->timestamp('pinned_at')->nullable()->after('edited_at');
            $table->foreignId('pinned_by_id')->nullable()->after('pinned_at')
                ->constrained('users')->nullOnDelete();

            /*
             * Кого позвали — списком номеров.
             *
             * Номерами, а не разбором текста при чтении: имя в теле реплики
             * человек мог набрать руками, а позвать — не позвать. Список пишется
             * в тот момент, когда упоминание выбрали из подсказки, и по нему
             * уходит уведомление.
             */
            $table->jsonb('mentions')->nullable()->after('forwarded');
        });

        /*
         * Закреплённые достаются на каждое открытие переписки, и их единицы на
         * десятки тысяч реплик. Индекс частичный: незакреплённым в нём места не
         * нужно вовсе, а порядок в определении тот же, в каком их читают, —
         * последнее закреплённое первым.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX messages_pinned_idx ON messages (conversation_id, pinned_at DESC)
            WHERE pinned_at IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_pinned_idx');

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pinned_by_id');
            $table->dropColumn(['forwarded', 'mentions', 'pinned_at']);
        });
    }
};
