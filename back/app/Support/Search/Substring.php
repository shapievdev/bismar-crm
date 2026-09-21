<?php

declare(strict_types=1);

namespace App\Support\Search;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Поиск вхождением подстроки — один на все списки приложения.
 *
 * Людей, групп, отделов, документов и курсов искали пятью почти одинаковыми
 * условиями, и каждое повторяло три правила: коллацию ICU, экранирование
 * подстановочных знаков и перебор колонок. Правил стало четыре — к ним добавилась
 * забытая раскладка (см. KeyboardLayout), — и пятикратное повторение перестало
 * быть терпимым: достаточно однажды поправить четыре места из пяти.
 *
 * Коллация ICU обязательна: базы собраны с C-сортировкой, где ILIKE складывает
 * регистр только латиницы, и «касса» иначе не нашла бы «Кассовую».
 *
 * `%` и `_` в запросе — то, что человек ввёл, а не то, что он имел в виду:
 * набравший «100%» ищет сотню процентов, а не «сто и что угодно».
 */
final readonly class Substring
{
    /**
     * Сужает выборку до строк, где запрос встречается хоть в одной из колонок.
     *
     * Пустой запрос ничего не сужает: списки ищут по той же строке, по которой
     * показывают всё, и «ничего не введено» значит «покажи всё».
     *
     * Колонки подставляются в SQL как есть — это часть запроса, а не ввод
     * человека: кроме имён, здесь допустимы и выражения вроде `keywords::text`.
     * Вызывать только с тем, что названо в коде.
     *
     * @param  Builder  $query  условие добавляется одной скобкой, чтобы не
     *                          рассыпать «или» по чужим условиям
     * @param  list<string>  $columns
     */
    public static function apply(Builder $query, ?string $term, array $columns): void
    {
        $patterns = self::patterns($term);

        if ($patterns === [] || $columns === []) {
            return;
        }

        $query->where(function (Builder $query) use ($patterns, $columns): void {
            foreach ($patterns as $pattern) {
                foreach ($columns as $column) {
                    $query->orWhereRaw(
                        sprintf('%s COLLATE "und-x-icu" ILIKE ?', $column),
                        [$pattern],
                    );
                }
            }
        });
    }

    /**
     * Запрос как образцы для ILIKE — по одному на каждое чтение раскладки.
     *
     * @return list<string>
     */
    private static function patterns(?string $term): array
    {
        return array_map(
            static fn (string $reading): string => '%'.self::escaped($reading).'%',
            KeyboardLayout::readings($term),
        );
    }

    /**
     * Знаки, которые в ILIKE значат «что угодно», — обратно в самих себя.
     *
     * Обратная косая идёт первой: экранировав её после, мы экранировали бы и
     * ту, которую сами только что поставили.
     */
    private static function escaped(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
