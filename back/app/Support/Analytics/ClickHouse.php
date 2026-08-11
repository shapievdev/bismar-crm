<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Единственная дверь в ClickHouse.
 *
 * Всё, что аналитика умеет делать с внешним сервером, — задать вопрос и
 * получить строки. Ни моделей, ни билдера, ни записи: у подключения права
 * читателя, и слой это отражает буквально, а не полагается на то, что сервер
 * откажет.
 *
 * Класс держит на себе три обязанности, которые иначе расползлись бы по
 * отчётам: подстановку имени базы, кэш и поведение при недоступном сервере.
 * Последнее важнее прочего — ClickHouse стоит в локальной сети, и когда до
 * него нет доступа, дашборд обязан показать пустую панель с пояснением, а не
 * уронить страницу пятисоткой.
 *
 * Не final намеренно: это единственная точка, где приложение выходит наружу, и
 * тесты подменяют её наследником (Tests\Support\FakeClickHouse). Иначе проверка
 * прав на маршрутах требовала бы живого сервера аналитики.
 */
class ClickHouse
{
    /**
     * Ответ на запрос.
     *
     * @param  array<string, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = DB::connection($this->connection())->select($this->qualify($sql), $bindings);

            return $rows;
        } catch (Throwable $exception) {
            // Сообщение сервера пишется в журнал целиком — по нему чинят
            // выгрузку, — а наружу уходит только факт недоступности: в тексте
            // ошибки ClickHouse лежит наш SQL со схемой чужой базы.
            Log::error('Аналитика: запрос к ClickHouse не выполнен', [
                'message' => $exception->getMessage(),
                'sql' => $sql,
            ]);

            throw new AnalyticsUnavailable(
                'Сервер аналитики недоступен. Цифры появятся, когда связь восстановится.',
                previous: $exception,
            );
        }
    }

    /**
     * Первая строка ответа — для запросов, которые по устройству возвращают
     * ровно одну (сводка, итог, максимум даты).
     *
     * @param  array<string, mixed>  $bindings
     * @return array<string, mixed>
     */
    public function selectOne(string $sql, array $bindings = []): array
    {
        return $this->select($sql, $bindings)[0] ?? [];
    }

    /**
     * Ответ из кэша, а если его там нет — из ClickHouse.
     *
     * Ключ строит вызывающий: только он знает, от чего его ответ зависит.
     * Ошибка не кэшируется — недоступный сервер не должен оставлять после себя
     * пустую панель на пять минут после того, как связь вернулась.
     *
     * @param  callable(): T  $query
     * @return T
     *
     * @template T
     */
    public function remember(string $key, callable $query): mixed
    {
        return Cache::remember(
            'analytics:'.sha1($key),
            (int) config('analytics.cache_ttl'),
            $query,
        );
    }

    /**
     * Подстановка имени базы вместо `{db}`.
     *
     * Имя таблицы в ClickHouse нельзя передать плейсхолдером, поэтому оно
     * попадает в SQL склейкой — единственной во всём слое и только из конфига.
     * Пользовательские значения этот путь не проходят никогда.
     */
    private function qualify(string $sql): string
    {
        return str_replace('{db}', $this->database(), $sql);
    }

    public function database(): string
    {
        /** @var string $database */
        $database = config('analytics.database');

        return $database;
    }

    private function connection(): string
    {
        /** @var string $connection */
        $connection = config('analytics.connection');

        return $connection;
    }
}