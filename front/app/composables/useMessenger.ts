import type { ChatMessage, ChatPerson, Conversation } from '~/types/chat'

/**
 * Живое состояние мессенджера — одно на всё приложение.
 *
 * Через useState, а не через ref внутри composable: счётчик непрочитанного
 * висит в навигации, а лента открыта на странице, и это должны быть те же
 * данные. Иначе бейдж и переписка разойдутся — сообщение прочитано, а цифра
 * висит.
 *
 * Подписки живут столько же, сколько вкладка: на личный канал подписываемся
 * один раз при входе и не отписываемся при уходе со страницы — сообщение,
 * пришедшее, пока человек смотрит аналитику, обязано зажечь бейдж.
 */
export function useMessenger() {
  const { $echo } = useNuxtApp()
  const api = useChatApi()
  const { user } = useAuth()

  const conversations = useState<Conversation[]>('chat.conversations', () => [])
  const unreadTotal = useState<number>('chat.unread', () => 0)
  const online = useState<number[]>('chat.online', () => [])
  const isConnected = useState<boolean>('chat.connected', () => false)

  /** Открытая лента: сообщения и кто в ней сейчас печатает. */
  const activeId = useState<number | null>('chat.active', () => null)
  const messages = useState<ChatMessage[]>('chat.messages', () => [])
  const typing = useState<ChatPerson[]>('chat.typing', () => [])
  const hasMore = useState<boolean>('chat.has-more', () => false)

  const active = computed(() => conversations.value.find(one => one.id === activeId.value) ?? null)

  /* ---------- Подключение ---------- */

  /**
   * Подписывается на своё и на присутствие. Зовётся один раз за вкладку.
   *
   * Без сокет-сервера всё продолжает работать: список и лента читаются
   * запросами, просто новое не приезжает само.
   */
  async function connect(): Promise<void> {
    if (!import.meta.client || isConnected.value) {
      return
    }

    isConnected.value = true

    await Promise.all([refreshConversations(), refreshUnread()])

    const me = user.value?.id

    if (!$echo || !me) {
      return
    }

    // Личный канал: сюда приходит всё сказанное в любой моей переписке — и той,
    // что сейчас закрыта. Подписываться ради этого на каждую было бы полсотни
    // подписок вместо одной.
    $echo.private(`users.${me}`)
      .listen('.message.sent', (event: { conversation_id: number, message: ChatMessage }) => {
        absorb(event.conversation_id, event.message)
      })

    $echo.join('presence.employees')
      .here((people: ChatPerson[]) => {
        online.value = people.map(one => one.id)
      })
      .joining((person: ChatPerson) => {
        online.value = [...new Set([...online.value, person.id])]
      })
      .leaving((person: ChatPerson) => {
        online.value = online.value.filter(id => id !== person.id)
      })
  }

  function isOnline(personId: number | null | undefined): boolean {
    return personId !== null && personId !== undefined && online.value.includes(personId)
  }

  /* ---------- Список переписок ---------- */

  async function refreshConversations(): Promise<void> {
    conversations.value = (await api.fetchConversations()).data
  }

  async function refreshUnread(): Promise<void> {
    unreadTotal.value = (await api.fetchUnread()).data.unread
  }

  /**
   * Принимает сказанное: в ленту, если переписка открыта, и в список — всегда.
   *
   * Своё сообщение сюда тоже приходит, эхом от сервера: страница уже показала
   * его сразу после отправки, поэтому проверяем, нет ли его в ленте.
   */
  function absorb(conversationId: number, message: ChatMessage): void {
    const mine = message.author?.id === user.value?.id
    const isOpen = activeId.value === conversationId

    if (isOpen && !messages.value.some(one => one.id === message.id)) {
      messages.value = [...messages.value, message]
    }

    const conversation = conversations.value.find(one => one.id === conversationId)

    if (!conversation) {
      // Первое сообщение в переписке, о которой вкладка ещё не знает: кто-то
      // написал впервые. Проще перечитать список, чем собирать её по кускам.
      void refreshConversations()
      void refreshUnread()

      return
    }

    conversation.last_message = message
    conversation.last_message_at = message.created_at

    if (!mine && !isOpen) {
      conversation.unread_count += 1
      unreadTotal.value += 1
    }

    // Свежая переписка — наверх: список читается сверху вниз.
    conversations.value = [
      conversation,
      ...conversations.value.filter(one => one.id !== conversationId),
    ]

    if (isOpen && !mine) {
      void markRead(conversationId)
    }
  }

  /* ---------- Открытая переписка ---------- */

  let thread: ReturnType<NonNullable<typeof $echo>['private']> | null = null

  /** Открывает переписку: читает ленту, подписывается на неё и гасит счётчик. */
  async function open(id: number): Promise<void> {
    if (activeId.value === id) {
      return
    }

    closeThread()

    activeId.value = id
    messages.value = []
    typing.value = []

    const { data } = await api.fetchMessages(id)

    messages.value = data
    hasMore.value = data.length >= 40

    await markRead(id)

    if (!$echo) {
      return
    }

    thread = $echo.private(`conversations.${id}`)

    thread
      .listen('.message.sent', (event: { conversation_id: number, message: ChatMessage }) => {
        absorb(event.conversation_id, event.message)
      })
      .listen('.messages.read', (event: { user_id: number, read_at: string }) => {
        const conversation = conversations.value.find(one => one.id === id)
        const person = conversation?.participants?.find(one => one.id === event.user_id)

        if (person) {
          person.last_read_at = event.read_at
        }
      })
      // Набор текста идёт мимо сервера, от клиента к клиенту: писать в базу
      // «он печатает» бессмысленно — это состояние живёт секунду.
      .listenForWhisper('typing', (person: ChatPerson) => {
        if (person.id === user.value?.id) {
          return
        }

        typing.value = [...typing.value.filter(one => one.id !== person.id), person]

        window.setTimeout(() => {
          typing.value = typing.value.filter(one => one.id !== person.id)
        }, 3000)
      })
  }

  /** Догружает то, что было раньше. */
  async function loadOlder(): Promise<void> {
    const id = activeId.value
    const oldest = messages.value[0]

    if (!id || !oldest || !hasMore.value) {
      return
    }

    const { data } = await api.fetchMessages(id, oldest.id)

    hasMore.value = data.length >= 40
    messages.value = [...data, ...messages.value]
  }

  async function send(body: string, files: File[] = []): Promise<void> {
    const id = activeId.value

    if (!id) {
      return
    }

    const { data } = await api.sendMessage(id, body, files)

    // Показываем сразу, не дожидаясь эха от сокета: своё сообщение должно
    // появляться мгновенно, иначе поле ввода очищается «в никуда».
    if (!messages.value.some(one => one.id === data.id)) {
      messages.value = [...messages.value, data]
    }

    const conversation = conversations.value.find(one => one.id === id)

    if (conversation) {
      conversation.last_message = data
      conversation.last_message_at = data.created_at
      conversations.value = [conversation, ...conversations.value.filter(one => one.id !== id)]
    }
  }

  /** Сообщает собеседнику, что мы печатаем. */
  function announceTyping(): void {
    if (!thread || !user.value) {
      return
    }

    thread.whisper('typing', {
      id: user.value.id,
      name: user.value.name,
      avatar_url: user.value.avatar_url ?? null,
    })
  }

  async function markRead(id: number): Promise<void> {
    const conversation = conversations.value.find(one => one.id === id)
    const seen = conversation?.unread_count ?? 0

    if (conversation) {
      conversation.unread_count = 0
    }

    unreadTotal.value = Math.max(0, unreadTotal.value - seen)

    await api.markRead(id)
  }

  function closeThread(): void {
    if (thread && activeId.value !== null) {
      $echo?.leave(`conversations.${activeId.value}`)
    }

    thread = null
    activeId.value = null
    messages.value = []
    typing.value = []
  }

  /** Пишем этому человеку: заводим переписку либо открываем прежнюю. */
  async function writeTo(personId: number): Promise<number> {
    const { data } = await api.startDirect(personId)

    if (!conversations.value.some(one => one.id === data.id)) {
      conversations.value = [data, ...conversations.value]
    }

    return data.id
  }

  return {
    conversations,
    unreadTotal,
    messages,
    typing,
    hasMore,
    activeId,
    active,
    isOnline,
    connect,
    refreshConversations,
    open,
    closeThread,
    loadOlder,
    send,
    announceTyping,
    markRead,
    writeTo,
  }
}