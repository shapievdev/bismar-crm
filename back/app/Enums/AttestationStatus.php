<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Что стало с попыткой после отправки.
 *
 * У обычного теста ответ известен сразу, и никакого «между» у него нет —
 * отсюда `Auto`: попытка оценена приложением, спрашивать больше некого.
 *
 * У аттестации между отправкой и вердиктом есть ожидание, и оно должно быть
 * видно обеим сторонам: сотруднику — чтобы не гадать, дошла ли работа;
 * проверяющему — чтобы знать, что от него ждут ответа. Молчание здесь хуже
 * отказа: человек, отправивший работу в пустоту, отправит её ещё раз.
 */
enum AttestationStatus: string
{
    /** Обычный тест: оценило приложение. */
    case Auto = 'auto';

    /** Аттестация отправлена и ждёт человека. */
    case Pending = 'pending';

    /** Человек зачёл работу. */
    case Passed = 'passed';

    /** Человек не зачёл работу. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Проверено автоматически',
            self::Pending => 'Ждёт проверки',
            self::Passed => 'Зачтено',
            self::Failed => 'Не зачтено',
        };
    }

    /** Ждёт ли попытка человека прямо сейчас. */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /** Вынесен ли вердикт человеком. */
    public function isReviewed(): bool
    {
        return $this === self::Passed || $this === self::Failed;
    }
}
