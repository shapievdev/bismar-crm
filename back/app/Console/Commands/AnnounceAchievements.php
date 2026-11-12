<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConversationKind;
use App\Models\AchievementAward;
use App\Support\Chat\AchievementCard;
use App\Support\Chat\Notifications;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Досылает уведомления о значках, о которых человеку не сказали.
 *
 * Выдача достижения и рассказ о нём — разные дела, и второе может не
 * случиться: значок выдан до того, как завели ленту уведомлений; выдача
 * прошла, а запись в ленту упала; уведомление удалили вручную. Отменить
 * выдачу нельзя (заслугу не отбирают), а значит дыра остаётся навсегда —
 * повторный пересчёт маршрута её не закроет: он видит, что значок уже есть, и
 * молчит.
 *
 * Поэтому несказанное ищется не по памяти, а по факту: для каждой выдачи
 * спрашиваем, лежит ли в ленте владельца уведомление про этот значок.
 * Безвредна при повторе и может ходить по расписанию.
 */
final class AnnounceAchievements extends Command
{
    protected $signature = 'chat:announce-achievements';

    protected $description = 'Досылает уведомления о выданных достижениях, о которых не сказали';

    public function handle(Notifications $notifications): int
    {
        $already = $this->alreadyAnnounced();
        $sent = 0;

        AchievementAward::query()
            ->with(['user', 'achievement'])
            ->orderBy('id')
            ->chunk(200, function ($awards) use ($notifications, $already, &$sent): void {
                foreach ($awards as $award) {
                    $user = $award->user;
                    $achievement = $award->achievement;

                    // Человека могли удалить, значок — нет: он мягкий и
                    // приходит вместе со спрятанными.
                    if ($user === null || $achievement === null) {
                        continue;
                    }

                    if (in_array($user->getKey().':'.$achievement->getKey(), $already, true)) {
                        continue;
                    }

                    $notifications->send(
                        $user,
                        sprintf('Новое достижение: «%s»', $achievement->title),
                        AchievementCard::snapshot($achievement, (string) $award->reason),
                    );

                    $sent++;
                }
            });

        $this->info($sent === 0 ? 'Все выданные значки объявлены.' : "Досказано: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * Пары «человек:значок», о которых в ленте уже сказано.
     *
     * Одним запросом: выдач могут быть тысячи, и спрашивать базу про каждую
     * значило бы тысячу обращений ради одной строчки вывода.
     *
     * @return list<string>
     */
    private function alreadyAnnounced(): array
    {
        return DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.kind', ConversationKind::System->value)
            ->whereNull('messages.deleted_at')
            ->where('messages.about->kind', AchievementCard::KIND)
            ->whereNotNull('conversations.created_by_id')
            ->selectRaw("conversations.created_by_id || ':' || (messages.about->>'achievement_id') as pair")
            ->pluck('pair')
            ->map(static fn (mixed $pair): string => (string) $pair)
            ->all();
    }
}
