<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Что сказал о полученном ответе сам спрашивавший.
 *
 * Единственный сигнал, которого не даёт никакая эвристика. Журнал умеет
 * отличить «модель промолчала» от «модель не сослалась ни на что», но «ответила
 * не на то» выглядит для него удачей: ссылки на месте, материал найден. Знает
 * об этом только тот, кто спрашивал.
 */
enum AnswerFeedback: string
{
    case Helpful = 'helpful';

    case Unhelpful = 'unhelpful';

    public function label(): string
    {
        return match ($this) {
            self::Helpful => 'Ответ помог',
            self::Unhelpful => 'Ответ не помог',
        };
    }
}
