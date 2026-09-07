<?php

declare(strict_types=1);

namespace App\Support\Chat;

use App\Enums\AppealReason;

/**
 * Карточка «письмо пришло вот отсюда» — тем, что видно на экране.
 *
 * Названия и адрес лежат снимком со дня отправки (см. миграцию
 * `messages.about`), а подписи собираются сейчас: переименуй мы кнопку «Ответа
 * не хватило», старые сообщения должны читаться новыми словами — они об одном
 * и том же.
 *
 * Собирается здесь, а не в ресурсе сообщения, потому что читателей у неё двое:
 * лента разговора и поле ввода, где та же карточка стоит ещё до отправки. Одно
 * место на обоих — иначе однажды подпись поправят только в одном.
 */
final readonly class MaterialCard
{
    /**
     * Как называется то, с чего написали. Раздел важен: «документ» и
     * «справочник» — разные места, и искать правило среди справок читателю не
     * предлагается.
     */
    private const KIND_LABELS = [
        'course' => 'Курс',
        'lesson' => 'Урок',
        'document' => 'Документ',
        'handbook' => 'Справочник',
    ];

    /**
     * @param  mixed  $about  снимок из `messages.about` либо свежий из MaterialReference
     * @return array<string, string|null>|null
     */
    public static function render(mixed $about): ?array
    {
        if (! is_array($about) || ! isset($about['kind'], $about['title'])) {
            return null;
        }

        $reason = AppealReason::tryFrom((string) ($about['reason'] ?? ''));

        return [
            'kind' => (string) $about['kind'],
            'kind_label' => self::KIND_LABELS[(string) $about['kind']] ?? 'Материал',
            'title' => (string) $about['title'],
            // Курс, внутри которого лежит урок: одно название урока не
            // говорит, где его искать.
            'context' => ($about['context'] ?? null) === null ? null : (string) $about['context'],
            'url' => ($about['url'] ?? null) === null ? null : (string) $about['url'],

            // Причины может и не быть: с карточки ответственного пишут вопрос,
            // а не замечание.
            'reason' => $reason?->value,
            'reason_label' => $reason?->label(),
        ];
    }
}
