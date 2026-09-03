<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Кто выносит приговор по тесту.
 *
 * Обычный тест проверяет приложение: у него на всё есть мерка — ключ у выбора,
 * эталон у письменного ответа. Аттестация — работа, а не ответ: заполненная
 * таблица, расчёт, разбор случая. Верны ли в ней числа, приложение знать не
 * может и делать вид, что может, не должно, — поэтому её читает человек.
 *
 * Отсюда и разница в том, что можно спросить: таблица допустима только на
 * аттестации, иначе обычный тест зачитывал бы её по заполненности, то есть ни
 * по чему.
 */
enum QuizKind: string
{
    /** Проверяет приложение, вердикт мгновенный. */
    case Standard = 'standard';

    /** Проверяет назначенный человек, вердикт приходит после. */
    case Attestation = 'attestation';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Обычный тест',
            self::Attestation => 'Аттестация',
        };
    }

    public function isAttestation(): bool
    {
        return $this === self::Attestation;
    }

    /**
     * Что показать в форме: вид и его объяснение.
     *
     * @return list<array{value: string, label: string, hint: string}>
     */
    public static function options(): array
    {
        return [
            [
                'value' => self::Standard->value,
                'label' => self::Standard->label(),
                'hint' => 'Проверяется сразу: выбор — по ключу, ответ своими словами — по смыслу. Таблицу спросить нельзя.',
            ],
            [
                'value' => self::Attestation->value,
                'label' => self::Attestation->label(),
                'hint' => 'Работу читает назначенный человек и решает, зачесть ли. Можно спрашивать таблицами.',
            ],
        ];
    }
}
