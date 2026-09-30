import type { ChannelAuthorizationCallback, Channel as PusherChannel } from 'pusher-js'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

/**
 * Канал здесь приходит от pusher-js, а не от laravel-echo.
 *
 * Имена совпадают, устройство — нет: у абстрактного `Channel` из laravel-echo
 * нет даже `name`, оно появляется только у наследника под конкретный
 * broadcaster. Подписывать канал по имени — работа pusher, его контракт тут и
 * нужен.
 */
type Authorizer = (channel: PusherChannel) => {
  authorize: (socketId: string, callback: ChannelAuthorizationCallback) => void
}

/** Что отдаёт Laravel на `/broadcasting/auth` — в той форме, какую ждёт pusher. */
type AuthPayload = NonNullable<Parameters<ChannelAuthorizationCallback>[1]>

/**
 * Живое соединение с сокет-сервером.
 *
 * Только в браузере: на сервере ни сокета, ни сессии — рендерить страницу это
 * не мешает, а держать соединение из Nitro на каждого посетителя было бы
 * бессмысленно и дорого.
 *
 * Подписка на закрытый канал подписывается приложением: клиент просит у Laravel
 * подпись на конкретное соединение, тот проверяет право (routes/channels.php) и
 * выдаёт её. Поэтому запрос идёт с куками и с CSRF-токеном — как всякий
 * изменяющий запрос в этом приложении.
 */
export default defineNuxtPlugin({
  name: 'echo',

  // Тип возвращаемого объявлен, а не выведен: без него вывод берёт первую
  // ветку — `echo: null` — и потребители получают пустой тип вместо соединения.
  setup(): { provide: { echo: Echo<'reverb'> | null } } {
    const {
      public: { apiBase, reverbKey, reverbHost, reverbPort, reverbScheme },
    } = useRuntimeConfig()

    // Ключа нет — сокетов нет. Приложение при этом работает: мессенджер просто
    // не обновляется сам, и это честнее, чем падать при старте.
    if (!reverbKey) {
      return { provide: { echo: null } }
    }

    // laravel-echo ищет Pusher в глобальной области.
    ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher

    const echo = new Echo({
      broadcaster: 'reverb',
      key: reverbKey as string,
      wsHost: reverbHost as string,
      wsPort: Number(reverbPort),
      wssPort: Number(reverbPort),
      forceTLS: reverbScheme === 'https',
      enabledTransports: ['ws', 'wss'],

      authorizer: ((channel: PusherChannel) => ({
        authorize: (socketId: string, callback: ChannelAuthorizationCallback) => {
          $fetch<AuthPayload>('/broadcasting/auth', {
            baseURL: apiBase as string,
            method: 'POST',
            credentials: 'include',
            headers: {
              Accept: 'application/json',
              'X-XSRF-TOKEN': readXsrfCookie() ?? '',
            },
            body: { socket_id: socketId, channel_name: channel.name },
          })
            // Отказ уезжает ошибкой, а не `true`: pusher читает у него
            // `message` и кладёт в событие `pusher:subscription_error` — с
            // логическим значением там оказывалось бы `undefined`, и причина
            // отказа терялась бы по дороге.
            .then(data => callback(null, data))
            .catch(error => callback(error instanceof Error ? error : new Error(String(error)), null))
        },
      })) satisfies Authorizer,
    })

    return { provide: { echo } }
  },
})

/**
 * Токен из куки, которую Laravel ставит для защиты от подделки запросов.
 *
 * Куку ставит $api при первом обращении — до мессенджера человек успевает
 * сходить хотя бы за собственным профилем, поэтому отдельно её здесь не
 * запрашиваем.
 */
function readXsrfCookie(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)

  return match?.[1] ? decodeURIComponent(match[1]) : null
}
