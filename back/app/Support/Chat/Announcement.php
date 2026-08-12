<?php

declare(strict_types=1);

namespace App\Support\Chat;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Рассылка события переписки — попыткой, а не обязательством.
 *
 * События мессенджера объявлены `ShouldBroadcastNow` и уходят синхронно, внутри
 * того же запроса. Значит, недоступный сокет-сервер бросает исключение прямо
 * посреди отправки — а сообщение к этому моменту уже записано и транзакция
 * закрыта. Без этой обёртки сотрудник видел пятисотую и писал заново, хотя
 * сказанное лежало в базе; а стоило серверу сокетов упасть — переписка целиком
 * переставала принимать сообщения.
 *
 * Здесь та же граница, что и у StoredFiles: дело сделано, когда запись
 * сохранена. Разослать её собеседникам — отдельная задача, и её неудача
 * отменяет только доставку. Собеседник увидит сказанное при следующем открытии
 * страницы, а не никогда.
 */
final class Announcement
{
    public static function attempt(object $event): void
    {
        try {
            Event::dispatch($event);
        } catch (Throwable $exception) {
            Log::warning('Событие переписки не ушло в эфир.', [
                'event' => $event::class,
                'exception' => $exception,
            ]);
        }
    }
}
