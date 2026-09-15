<?php

declare(strict_types=1);

namespace App\Support\Chat;

use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;

/**
 * Отклики под репликой, свёрнутые к тому, что показывают: знак, сколько и кто.
 *
 * Своё «я тоже так отметил» здесь не считается, и это осознанно. Тот же список
 * уходит в эфир всем участникам сразу, а «своё» у каждого из них своё; вычисляя
 * его здесь, пришлось бы рассылать по отдельному сообщению на человека. Вместо
 * этого отдаются номера откликнувшихся — и вкладка сама узнаёт себя в списке.
 *
 * Имена нужны для подсказки «кто именно»: в группе на двадцать человек «7 👍»
 * без имён не отвечает ровно на тот вопрос, ради которого на них смотрят.
 */
final readonly class ReactionSummary
{
    /** Скольких называют поимённо: дальше подсказка всё равно не читается. */
    private const NAMED = 12;

    /**
     * @return list<array{emoji: string, count: int, user_ids: list<int>, people: list<string>}>
     */
    public static function of(Message $message): array
    {
        return $message->reactions
            ->groupBy('emoji')
            ->map(fn ($group, string $emoji): array => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'user_ids' => $group->map(fn (MessageReaction $one): int => (int) $one->user_id)
                    ->values()
                    ->all(),
                'people' => $group->take(self::NAMED)
                    ->map(fn (MessageReaction $one): string => $one->relationLoaded('person')
                        ? ($one->person?->shortName ?? 'Бывший сотрудник')
                        : '')
                    ->filter()
                    ->values()
                    ->all(),
            ])
            // Чаще отмеченное — первым, а при равенстве держимся порядка знаков
            // в подсказке: иначе одинаковые отклики прыгают местами от загрузки
            // к загрузке, и нажать по второму разу можно мимо.
            ->sort(fn (array $left, array $right): int => $right['count'] <=> $left['count']
                ?: self::place($left['emoji']) <=> self::place($right['emoji']))
            ->values()
            ->all();
    }

    /** Место знака в подсказке; неизвестный — в конец. */
    private static function place(string $emoji): int
    {
        $at = array_search($emoji, Reactions::ALLOWED, strict: true);

        return $at === false ? count(Reactions::ALLOWED) : $at;
    }

    /**
     * Откликнулся ли этот человек — и чем.
     *
     * @param  list<array{emoji: string, count: int, user_ids: list<int>, people: list<string>}>  $summary
     */
    public static function mine(array $summary, ?User $person): ?string
    {
        if ($person === null) {
            return null;
        }

        foreach ($summary as $one) {
            if (in_array($person->getKey(), $one['user_ids'], strict: true)) {
                return $one['emoji'];
            }
        }

        return null;
    }
}
