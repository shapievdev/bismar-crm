<?php

declare(strict_types=1);

namespace App\Support\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Отправка уведомлений в браузеры.
 *
 * Служба доставки у каждого браузера своя, но разговаривают с ними одинаково —
 * этим и занимается WebPush из библиотеки. Здесь — то, что вокруг: настроены
 * ли ключи, кому слать и что делать с адресами, которые больше не отвечают.
 */
class WebPushSender
{
    /**
     * Настроены ли ключи.
     *
     * Без них приложение живёт как жило: отправка тихо ничего не делает.
     * Падать на каждом сообщении в мессенджере из-за незаполненного `.env` —
     * худшее, что можно сделать с разработкой.
     */
    public function isConfigured(): bool
    {
        return is_string(config('push.vapid.public_key')) && config('push.vapid.public_key') !== ''
            && is_string(config('push.vapid.private_key')) && config('push.vapid.private_key') !== '';
    }

    /**
     * Рассылает одно уведомление по подписанным устройствам.
     *
     * Отправка идёт пачкой: библиотека держит соединения к службам доставки и
     * шлёт по одному запросу на устройство, но кладёт их в очередь сама.
     *
     * @param  Collection<int, PushSubscription>  $subscriptions
     */
    public function send(Collection $subscriptions, PushMessage $message): void
    {
        if ($subscriptions->isEmpty() || ! $this->isConfigured()) {
            return;
        }

        try {
            $push = $this->client();
        } catch (Throwable $error) {
            // Кривые ключи — беда настройки, а не повод уронить очередь: она
            // повторит задачу и снова упрётся в те же ключи.
            Log::warning('Уведомления не отправлены: ключи VAPID не приняты.', [
                'error' => $error->getMessage(),
            ]);

            return;
        }

        $payload = json_encode($message->toArray(), JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $subscription) {
            $push->queueNotification($this->describe($subscription), $payload === false ? null : $payload);
        }

        foreach ($push->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            // Устройство отписалось или адрес протух — строка больше ничего не
            // значит. Не убрав её, мы год спустя будем стучаться в телефоны,
            // которых нет.
            if ($report->isSubscriptionExpired()) {
                PushSubscription::query()->where('endpoint', $report->getEndpoint())->delete();

                continue;
            }

            Log::info('Уведомление не доставлено.', [
                'endpoint' => $report->getEndpoint(),
                'reason' => $report->getReason(),
            ]);
        }
    }

    /**
     * @throws \ErrorException
     */
    protected function client(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject' => (string) config('push.vapid.subject'),
                'publicKey' => (string) config('push.vapid.public_key'),
                'privateKey' => (string) config('push.vapid.private_key'),
            ],
        ], ['TTL' => (int) config('push.ttl')]);
    }

    private function describe(PushSubscription $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => $subscription->endpoint,
            'publicKey' => $subscription->public_key,
            'authToken' => $subscription->auth_token,
            // Шифрование, о котором договорились все браузеры; другого стандарт
            // сегодня и не знает.
            'contentEncoding' => 'aesgcm',
        ]);
    }
}
