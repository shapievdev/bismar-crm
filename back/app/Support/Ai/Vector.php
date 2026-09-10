<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Нормирование векторов и сравнение их между собой.
 *
 * В базе вектор лежит типом `vector` расширения pgvector, и близость там считает
 * сама база — по индексу, не читая всё подряд. Приложению остаётся две вещи:
 * привести вектор к единичной длине перед записью и уметь сравнить два вектора,
 * которых в базе нет вовсе.
 *
 * Нормирование важнее, чем кажется. От него зависит, что косинус вырождается в
 * скалярное произведение, а расстояния по косинусу, по скалярному произведению и
 * евклидово упорядочивают одинаково: пороги в config/ai.php выражены в косинусе,
 * и индекс, построенный по любой из этих мер, даёт тот же порядок. Записать
 * ненормированный вектор — значит тихо развести шкалу порогов со шкалой индекса.
 */
final readonly class Vector
{
    /**
     * Вектор в записи, которую понимает pgvector: `[0.1,-0.2,…]`.
     *
     * Нормируется здесь же, а не доверяется зовущему: колонка не умеет
     * потребовать единичной длины, и единственное место, где это можно
     * гарантировать, — то, через которое вектор проходит по дороге в базу.
     *
     * @param  list<float>  $values
     */
    public static function literal(array $values): string
    {
        return '['.implode(',', self::normalised($values)).']';
    }

    /**
     * Тот же вектор единичной длины — для сравнений, минующих базу.
     *
     * @param  list<float>  $values
     * @return list<float>
     */
    public static function normalised(array $values): array
    {
        $length = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $values)));

        if ($length <= 0.0) {
            return $values;
        }

        return array_map(static fn (float $v): float => $v / $length, $values);
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
}
