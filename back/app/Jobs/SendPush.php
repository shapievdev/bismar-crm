<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Support\Push\PushMessage;
use App\Support\Push\WebPushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Уведомление людям — очередью, а не по ходу запроса.
 *
 * Отправка идёт в чужие службы доставки, по запросу на устройство: делать это,
 * пока человек ждёт ответа на «отправить сообщение», значит поставить скорость
 * мессенджера в зависимость от того, как сегодня отвечает Google.
 *
 * Живое время у сообщения своё — Reverb, и он остаётся мгновенным. Push нужен
 * для закрытой вкладки, и секунда задержки там ничего не значит.
 *
 * @see WebPushSender
 */
final class SendPush implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $userIds  кому; уволенные отсеиваются при отправке
     */
    public function __construct(
        private readonly array $userIds,
        private readonly PushMessage $message,
    ) {}

    public function handle(WebPushSender $sender): void
    {
        if ($this->userIds === []) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->whereIn('user_id', $this->userIds)
            // Уволенному не шлют: платформа для него закрыта, и уведомление
            // звало бы туда, куда его не пустят.
            ->whereHas('user', fn ($query) => $query->whereNull('dismissed_at'))
            ->get();

        $sender->send($subscriptions, $this->message);
    }
}
