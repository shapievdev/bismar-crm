<?php

declare(strict_types=1);

namespace App\Support\Chat;

use App\Models\Achievement;

/**
 * Карточка достижения под текстом уведомления.
 *
 * Та же форма, что у карточки материала (MaterialCard), и потому рисуется той
 * же разметкой: заголовок вида, название, пояснение, ссылка. Разными их делает
 * только одно — картинка, и ради неё карточка отдельная.
 *
 * В снимке лежит номер значка, а не ссылка на картинку: ссылки в бакет
 * подписанные и живут час, а уведомление остаётся в ленте навсегда. Поэтому
 * адрес собирается при чтении — как у аватарки.
 *
 * Название и причина всё же снимком, рядом с номером: значок могут
 * переименовать или спрятать, а сказано было то, что сказано.
 */
final readonly class AchievementCard
{
    public const KIND = 'achievement';

    /**
     * Снимок для `messages.about` — то, что ложится в базу.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Achievement $achievement, string $reason): array
    {
        return [
            'kind' => self::KIND,
            'achievement_id' => (int) $achievement->getKey(),
            'title' => $achievement->title,
            'context' => $reason,
            // Значки живут в профиле — туда карточка и ведёт.
            'url' => '/profile',
        ];
    }

    /**
     * @param  mixed  $about  снимок из `messages.about`
     * @return array<string, mixed>|null
     */
    public static function render(mixed $about): ?array
    {
        if (! is_array($about) || ($about['kind'] ?? null) !== self::KIND) {
            return null;
        }

        return [
            'kind' => self::KIND,
            'kind_label' => 'Достижение',
            'title' => (string) ($about['title'] ?? ''),
            'context' => ($about['context'] ?? null) === null ? null : (string) $about['context'],
            'url' => ($about['url'] ?? null) === null ? null : (string) $about['url'],

            // Поля карточки материала: разметка у них общая, и присылать её
            // неполной значило бы заставить экран гадать, какая из двух пришла.
            'reason' => null,
            'reason_label' => null,

            'image_url' => self::imageOf($about),
        ];
    }

    /**
     * @param  array<string, mixed>  $about
     */
    private static function imageOf(array $about): ?string
    {
        $id = $about['achievement_id'] ?? null;

        if ($id === null) {
            return null;
        }

        // Вместе со спрятанными: значок убрали из каталога, а полученное
        // уведомление о нём осталось, и картинка в нём — та же самая.
        return Achievement::withTrashed()->find((int) $id)?->imageUrl();
    }
}
