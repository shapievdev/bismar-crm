<?php

declare(strict_types=1);

namespace App\Support\Lms;

/**
 * Ключевые слова материала, приведённые к порядку.
 *
 * Список набирают руками, и приходит он таким, каким набрали: с пробелами по
 * краям, с пустыми строками от лишней запятой, с «Касса» рядом с «касса».
 * Порядок наводится в одном месте — иначе он разойдётся между курсом,
 * документом и тем, что уедет в индекс.
 *
 * Регистр слова сохраняется тот, каким его написали первым: список видит автор,
 * и приведение всего к нижнему регистру превратило бы «1С» в «1с».
 */
final class Keywords
{
    /** Больше двадцати слов — это уже не ключевые слова, а пересказ. */
    public const LIMIT = 20;

    /** Длиннее — это фраза; искать по ней всё равно никто не станет. */
    public const MAX_LENGTH = 60;

    /**
     * @param  array<int, mixed>  $keywords
     * @return list<string>
     */
    public static function clean(array $keywords): array
    {
        $seen = [];
        $clean = [];

        foreach ($keywords as $keyword) {
            if (! is_string($keyword)) {
                continue;
            }

            // Пробелы внутри тоже схлопываются: «холодная  вода» и «холодная
            // вода» — одно слово, а в списке стояли бы двумя строками.
            $keyword = trim((string) preg_replace('/\s+/u', ' ', $keyword));

            if ($keyword === '' || mb_strlen($keyword) > self::MAX_LENGTH) {
                continue;
            }

            $key = mb_strtolower($keyword);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $clean[] = $keyword;

            if (count($clean) >= self::LIMIT) {
                break;
            }
        }

        return $clean;
    }
}
