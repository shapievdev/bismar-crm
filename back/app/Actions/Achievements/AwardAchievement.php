<?php

declare(strict_types=1);

namespace App\Actions\Achievements;

use App\Models\Achievement;
use App\Models\AchievementAward;
use App\Models\User;
use App\Support\Chat\AchievementCard;
use App\Support\Chat\Notifications;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Выдаёт сотруднику достижение — один раз и насовсем.
 *
 * Звать можно сколько угодно: пара (достижение, человек) уникальна, и
 * повторная выдача возвращает null, ничего не трогая. На это свойство и
 * опирается адаптация — там выдача идёт из ленивого пересчёта маршрута,
 * который случается при каждом взгляде на карту.
 *
 * Отобрать выданное нечем намеренно: достижение не ярлык, а заслуга. Спрятать
 * значок из каталога можно, из чужого профиля — нет.
 */
final readonly class AwardAchievement
{
    /** Причина живёт в varchar(255), а текст задания бывает в тысячу знаков. */
    private const REASON_LIMIT = 200;

    public function __construct(private Notifications $notifications) {}

    /**
     * @param  Achievement|int  $achievement  значок или его номер: номер дешевле,
     *                                        когда он просто лежит в строке шага,
     *                                        но тогда за названием для
     *                                        поздравления приходится сходить
     * @param  ?Model  $source  строка, закрытие которой его выдало
     * @param  ?CarbonInterface  $at  когда заслужено; по умолчанию — сейчас
     * @return ?AchievementAward null, если значок у человека уже был
     */
    public function handle(
        User $user,
        Achievement|int $achievement,
        string $reason,
        ?Model $source = null,
        ?CarbonInterface $at = null,
    ): ?AchievementAward {
        $id = $achievement instanceof Achievement ? (int) $achievement->getKey() : $achievement;

        $taken = AchievementAward::query()
            ->where('achievement_id', $id)
            ->where('user_id', $user->getKey())
            ->exists();

        if ($taken) {
            return null;
        }

        $award = AchievementAward::query()->create([
            'achievement_id' => $id,
            'user_id' => $user->getKey(),
            'awarded_at' => $at ?? Date::now(),
            'reason' => Str::limit($reason, self::REASON_LIMIT),
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
        ]);

        $model = $achievement instanceof Achievement
            ? $achievement
            : Achievement::withTrashed()->find($id);

        if ($model !== null) {
            $this->congratulate($user, $award, $model);
        }

        return $award;
    }

    /**
     * Поздравление — ради него значки и заводят.
     *
     * Уходит в «Уведомления» — личную ленту человека в мессенджере, — а не
     * отдельным push мимо неё: push у ленты свой, и ведёт он туда же, где
     * запись останется и завтра. Карточка под текстом несёт сам значок и
     * ведёт в профиль, где лежат все заработанные.
     */
    private function congratulate(User $user, AchievementAward $award, Achievement $achievement): void
    {
        $this->notifications->send(
            $user,
            sprintf('Новое достижение: «%s»', $achievement->title),
            AchievementCard::snapshot($achievement, (string) $award->reason),
        );
    }
}
