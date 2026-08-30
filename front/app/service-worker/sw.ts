/// <reference lib="webworker" />

import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching'

/**
 * Service worker приложения.
 *
 * Раньше его целиком писал за нас плагин PWA, и это устраивало, пока от
 * воркера нужен был только кэш оболочки. Уведомления показывает он же — и
 * вставить обработчик в сгенерированный файл нельзя, поэтому воркер теперь наш,
 * а плагин лишь подставляет в него список файлов сборки.
 *
 * Правила кэша остались прежними:
 *
 *  - кэшируется только оболочка: разметка, стили, шрифты, значки;
 *  - к API воркер не притрагивается вовсе — каждая страница здесь живёт ответом
 *    сервера, и показанный из кэша список задолженностей был бы хуже честного
 *    «нет связи», а после выхода из системы в кэше остались бы чужие данные;
 *  - переходы по страницам не перехватываются: разметку отдаёт сервер, и
 *    подменять её сохранённой оболочкой значит показать вчерашний каркас поверх
 *    сегодняшних данных.
 */

declare const self: ServiceWorkerGlobalScope

/** Что показать: это же собирает сервер — см. App\Support\Push\PushMessage. */
interface PushPayload {
  title: string
  body: string
  url: string
  tag: string
}

// Список файлов оболочки подставляет сборка.
precacheAndRoute(self.__WB_MANIFEST)
cleanupOutdatedCaches()

/*
 * Свежая версия заступает сразу, не дожидаясь, пока закроют все вкладки: так
 * же вёл себя прежний воркер (registerType: 'autoUpdate'), и приложение на это
 * рассчитывает — несохранённого состояния между вкладками оно не держит.
 */
self.addEventListener('install', () => {
  void self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim())
})

/**
 * Уведомление от сервера.
 *
 * Показать его обязаны: браузер отзовёт подписку у того, кто примет push и
 * промолчит. Поэтому даже на пустое или испорченное тело мы говорим хоть
 * что-то, а не выходим молча.
 */
self.addEventListener('push', (event) => {
  const payload = read(event.data)

  event.waitUntil(self.registration.showNotification(payload.title, {
    body: payload.body,
    tag: payload.tag,
    icon: '/icons/pwa-192x192.png',
    // Пришедшее с тем же именем заменяет прежнее — десять реплик из одной
    // переписки оставят одно уведомление. `renotify` заставляет телефон
    // отозваться и на замену: иначе новое сообщение приходило бы беззвучно.
    renotify: true,
    data: { url: payload.url },
  }))
})

/**
 * Нажатие на уведомление.
 *
 * Открытая вкладка приложения переиспользуется: открывать вторую, когда первая
 * уже есть, — верный способ развести два мессенджера на одном телефоне.
 */
self.addEventListener('notificationclick', (event) => {
  event.notification.close()

  const target = typeof event.notification.data?.url === 'string' ? event.notification.data.url : '/'

  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })

    for (const client of windows) {
      await client.focus()

      if ('navigate' in client) {
        await client.navigate(target)
      }

      return
    }

    await self.clients.openWindow(target)
  })())
})

function read(data: PushMessageData | null): PushPayload {
  const fallback: PushPayload = {
    title: 'Bismar CRM',
    body: 'Есть новое сообщение',
    url: '/',
    tag: 'bismar',
  }

  if (!data) {
    return fallback
  }

  try {
    const payload = data.json() as Partial<PushPayload>

    return {
      title: payload.title || fallback.title,
      body: payload.body || fallback.body,
      url: payload.url || fallback.url,
      tag: payload.tag || fallback.tag,
    }
  }
  catch {
    return { ...fallback, body: data.text() || fallback.body }
  }
}
