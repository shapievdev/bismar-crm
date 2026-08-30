<?php

declare(strict_types=1);

namespace App\Support\Push;

/**
 * Что человек увидит на экране телефона.
 *
 * Тело уведомления собирается здесь, а не в месте отправки: показывает его
 * service worker, и он ждёт одну и ту же форму, откуда бы уведомление ни
 * пришло — из мессенджера или из новостей.
 *
 * `tag` — имя, под которым уведомление ложится на экран: пришедшее с тем же
 * именем **заменяет** предыдущее. Поэтому у переписки он свой: десять реплик
 * подряд оставят одно уведомление, а не десять.
 */
final readonly class PushMessage
{
    public function __construct(
        public string $title,
        public string $body,
        /** Куда открыть приложение по нажатию — путь внутри SPA. */
        public string $url,
        public string $tag,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'tag' => $this->tag,
        ];
    }

    /**
     * Обрезает длинный текст: в уведомлении всё равно видно две-три строки, а
     * остальное система молча отрежет — лучше поставить многоточие самим.
     */
    public static function shorten(?string $text, int $limit = 120): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

        if ($text === '') {
            return '';
        }

        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit - 1).'…';
    }
}
