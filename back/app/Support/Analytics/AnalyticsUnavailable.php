<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Сервер аналитики не ответил.
 *
 * Отдельный тип, а не общая ошибка, потому что ответ на неё другой: это не
 * поломка CRM, а недоступность соседней системы. Реализует HttpException, и
 * потому Laravel сам отдаёт 503 с внятным текстом — фронту остаётся показать
 * его в панели, не разбирая исключения.
 */
final class AnalyticsUnavailable extends RuntimeException implements HttpExceptionInterface
{
    public function getStatusCode(): int
    {
        return 503;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        // Повторять запрос немедленно бессмысленно: если выгрузка упала,
        // поднимется она не через секунду.
        return ['Retry-After' => '60'];
    }
}