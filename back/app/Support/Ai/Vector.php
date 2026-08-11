<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Упаковка и сравнение векторов.
 *
 * Вектор хранится как base64 от последовательности float32 — вдвое-втрое
 * компактнее JSON и разбирается одним вызовом. Все векторы нормируются при
 * записи, поэтому косинус вырождается в скалярное произведение: делить на
 * длины при каждом сравнении не нужно.
 */
final readonly class Vector
{
    /**
     * @param  list<float>  $values
     */
    public static function pack(array $values): string
    {
        return base64_encode(pack('g*', ...self::normalise($values)));
    }

    /**
     * @return list<float>
     */
    public static function unpack(?string $packed): array
    {
        if ($packed === null || $packed === '') {
            return [];
        }

        $binary = base64_decode($packed, strict: true);

        return $binary === false ? [] : array_values(unpack('g*', $binary) ?: []);
    }

    /**
     * Близость двух нормированных векторов: от -1 до 1.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        $length = min(count($a), count($b));

        if ($length === 0) {
            return 0.0;
        }

        $sum = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    private static function normalise(array $values): array
    {
        $length = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $values)));

        if ($length <= 0.0) {
            return $values;
        }

        return array_map(static fn (float $v): float => $v / $length, $values);
    }
}
