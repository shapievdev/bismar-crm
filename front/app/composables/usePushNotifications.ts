/**
 * Уведомления на устройство.
 *
 * Подписка принадлежит устройству, а не человеку: телефон, рабочий компьютер и
 * домашний — три разные подписки, и включать уведомления надо на каждом. Здесь
 * — три вопроса и два действия: умеет ли браузер, спрошено ли разрешение,
 * подписано ли это устройство; включить и выключить.
 *
 * Разрешение спрашивается только по нажатию: браузеры давно наказывают за
 * спонтанный запрос, а Safari его попросту не покажет.
 */

interface PushState {
  /** Настроены ли ключи на сервере: без них подписываться не к кому. */
  configured: boolean
  public_key: string | null
  subscribed: boolean
}

export function usePushNotifications() {
  const { $api } = useNuxtApp()

  /*
   * Состояние общее на приложение: о нём спрашивают в двух местах разом —
   * полоса с предложением включить и переключатель в профиле. Заведи каждое
   * своё, и включённое в одном месте не дошло бы до другого.
   */
  const supported = useState('push.supported', () => false)
  const permission = useState<NotificationPermission>('push.permission', () => 'default')
  const enabled = useState('push.enabled', () => false)
  const configured = useState('push.configured', () => true)
  const asked = useState('push.asked', () => false)
  const isBusy = ref(false)
  const error = ref<string | null>(null)

  /**
   * iOS присылает уведомления только приложению, добавленному на домашний
   * экран, — на вкладке Safari у него нет даже PushManager. Отличаем этот
   * случай, чтобы сказать человеку правду вместо «браузер не умеет».
   */
  const needsInstall = computed(() => {
    if (!import.meta.client || supported.value) {
      return false
    }

    const isIos = /iP(hone|ad|od)/.test(navigator.userAgent)

    return isIos && !window.matchMedia('(display-mode: standalone)').matches
  })

  async function registration(): Promise<ServiceWorkerRegistration | null> {
    if (!supported.value) {
      return null
    }

    // Воркер регистрируется сборкой; в разработке его нет вовсе, и ждать его
    // молча означало бы повесить переключатель навсегда.
    return await Promise.race([
      navigator.serviceWorker.ready,
      new Promise<null>(resolve => setTimeout(() => resolve(null), 3000)),
    ])
  }

  async function ask(endpoint?: string): Promise<PushState | null> {
    try {
      const { data } = await $api<{ data: PushState }>('/api/push/subscription', {
        query: endpoint ? { endpoint } : undefined,
      })

      return data
    }
    catch {
      return null
    }
  }

  /**
   * Стоит ли предлагать включить их прямо сейчас.
   *
   * Предлагаем, пока не включено и пока не запрещено наглухо: запрет снимают в
   * настройках браузера, и кнопка на него не влияет.
   */
  const worthAsking = computed(() =>
    supported.value && configured.value && !enabled.value && permission.value !== 'denied')

  /** Что сейчас: умеет ли браузер, разрешено ли, подписаны ли мы. */
  async function refresh(): Promise<void> {
    supported.value = import.meta.client
      && 'serviceWorker' in navigator
      && 'PushManager' in window
      && 'Notification' in window

    if (!supported.value) {
      return
    }

    permission.value = Notification.permission

    const subscription = await (await registration())?.pushManager.getSubscription() ?? null
    const state = await ask(subscription?.endpoint)

    configured.value = state?.configured ?? false
    // Подписка считается включённой, только когда о ней знают обе стороны:
    // браузер мог сохранить её, а сервер — потерять при смене ключей.
    enabled.value = Boolean(subscription) && Boolean(state?.subscribed)
    asked.value = true
  }

  async function enable(): Promise<void> {
    isBusy.value = true
    error.value = null

    try {
      const state = await ask()

      if (!state?.configured || !state.public_key) {
        error.value = 'Уведомления не настроены на сервере.'

        return
      }

      permission.value = await Notification.requestPermission()

      if (permission.value !== 'granted') {
        error.value = permission.value === 'denied'
          ? 'Уведомления запрещены в настройках браузера — снимите запрет и попробуйте снова.'
          : null

        return
      }

      const ready = await registration()

      if (!ready) {
        error.value = 'Приложение ещё не установило служебный сценарий. Обновите страницу и попробуйте снова.'

        return
      }

      const subscription = await ready.pushManager.subscribe({
        // Тихих уведомлений не бывает: браузер требует показывать каждое,
        // иначе отзывает подписку. Отказаться от этого нельзя.
        userVisibleOnly: true,
        applicationServerKey: decodeKey(state.public_key),
      })

      const keys = subscription.toJSON().keys ?? {}

      await $api('/api/push/subscription', {
        method: 'POST',
        body: {
          endpoint: subscription.endpoint,
          public_key: keys.p256dh,
          auth_token: keys.auth,
          device: deviceName(),
        },
      })

      enabled.value = true
    }
    catch {
      error.value = 'Не удалось включить уведомления.'
    }
    finally {
      isBusy.value = false
    }
  }

  async function disable(): Promise<void> {
    isBusy.value = true
    error.value = null

    try {
      const subscription = await (await registration())?.pushManager.getSubscription() ?? null

      // Сервер первым: отписавшись в браузере и не сказав об этом, мы оставили
      // бы строку, в которую потом стучались бы годами.
      await $api('/api/push/subscription', {
        method: 'DELETE',
        body: { endpoint: subscription?.endpoint ?? '' },
      })

      await subscription?.unsubscribe()

      enabled.value = false
    }
    catch {
      error.value = 'Не удалось выключить уведомления.'
    }
    finally {
      isBusy.value = false
    }
  }

  return { supported, needsInstall, configured, permission, enabled, asked, worthAsking, isBusy, error, refresh, enable, disable }
}

/**
 * Публичный ключ приходит в base64url — так его отдаёт сервер и так его хранят
 * все, — а `pushManager.subscribe` ждёт байты.
 */
function decodeKey(value: string): ArrayBuffer {
  const padded = (value + '='.repeat((4 - value.length % 4) % 4))
    .replace(/-/g, '+')
    .replace(/_/g, '/')

  const raw = atob(padded)
  const bytes = new Uint8Array(raw.length)

  for (let index = 0; index < raw.length; index += 1) {
    bytes[index] = raw.charCodeAt(index)
  }

  // Возвращается сам буфер, а не типизированный массив: `subscribe` ждёт
  // BufferSource, и на нынешних типах TypeScript второе к первому не сводится.
  return bytes.buffer
}

/** Как назвать устройство в списке подписок — коротко и узнаваемо. */
function deviceName(): string {
  const agent = navigator.userAgent

  const known = [
    [/iPhone/, 'iPhone'],
    [/iPad/, 'iPad'],
    [/Android/, 'Android'],
    [/Macintosh/, 'Mac'],
    [/Windows/, 'Windows'],
    [/Linux/, 'Linux'],
  ] as const

  return known.find(([pattern]) => pattern.test(agent))?.[1] ?? 'Устройство'
}
