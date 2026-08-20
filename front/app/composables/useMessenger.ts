import type { ChatMessage, ChatPerson, Conversation, DeletionScope } from '~/types/chat'

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
      .listen('.message.edited', (event: { conversation_id: number, message: ChatMessage }) => {
        replace(event.conversation_id, event.message)
      })
      .listen('.message.deleted', (event: { conversation_id: number, message_id: number }) => {
        forget(event.conversation_id, event.message_id)
      })
      // Переписку удалили — у всех или нами же во второй вкладке. Приходит
      // только сюда: канала самой переписки к этому времени может уже не быть.
      .listen('.conversation.removed', (event: { conversation_id: number }) => {
        dismiss(event.conversation_id)
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

  /**
   * Реплику переписали: подменяем её целиком там, где она видна.
   *
   * Целиком, а не по полям: с сервера приходит та же структура, что при
   * отправке, и сращивать её по кускам значит держать в голове, какие поля
   * могли измениться.
   */
  function replace(conversationId: number, message: ChatMessage): void {
    if (activeId.value === conversationId) {
      messages.value = messages.value.map(one => (one.id === message.id ? message : one))
    }

    const conversation = conversations.value.find(one => one.id === conversationId)

    // Строчка в списке показывает последнее сказанное — и если правили именно
    // его, она обязана измениться вместе с ним.
    if (conversation?.last_message?.id === message.id) {
      conversation.last_message = message
    }
  }

  /**
   * Реплику убрали.
   *
   * Ответы на неё остаются, но их цитата протухла: сервер уже отдаёт её
   * помеченной удалённой, а у нас на руках прежняя. Правим на месте, чтобы не
   * перечитывать ленту целиком ради одной пометки.
   */
  function forget(conversationId: number, messageId: number): void {
    if (activeId.value === conversationId) {
      messages.value = messages.value
        .filter(one => one.id !== messageId)
        .map(one => (one.reply_to?.id === messageId
          ? { ...one, reply_to: { ...one.reply_to, deleted: true, excerpt: null } }
          : one))
    }

    const conversation = conversations.value.find(one => one.id === conversationId)

    // Удалили последнее сказанное — в списке надо показать предыдущее, а его у
    // вкладки нет. Это единственный случай, когда список перечитывается.
    if (conversation?.last_message?.id === messageId) {
      void refreshConversations()
    }
  }

  /**
   * Убирает переписку из списка — удалили её у всех или только у нас.
   *
   * Открытую при этом закрываем: держать на экране ленту разговора, которого
   * больше нет, значит показывать сообщения, которых никто не увидит. Куда
   * после этого деться со страницы, решает она сама — по пропаже из списка.
   */
  function dismiss(conversationId: number): void {
    const conversation = conversations.value.find(one => one.id === conversationId)

    if (conversation) {
      unreadTotal.value = Math.max(0, unreadTotal.value - conversation.unread_count)
      conversations.value = conversations.value.filter(one => one.id !== conversationId)
    }

    // Закрываем и тогда, когда в списке её уже не было: открыть переписку можно
    // и прямо адресом, минуя список.
    if (activeId.value === conversationId) {
      closeThread()
    }
  }

  /**
   * Удаляет переписку: у себя либо у всех.
   *
   * Из списка она уходит сразу, не дожидаясь эха от сокета: нажавший «удалить»
   * должен увидеть, что она удалена, даже когда сокет-сервер не поднят.
   */
  async function erase(conversationId: number, scope: DeletionScope): Promise<void> {
    await api.deleteConversation(conversationId, scope)

    dismiss(conversationId)
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
      .listen('.message.edited', (event: { conversation_id: number, message: ChatMessage }) => {
        replace(event.conversation_id, event.message)
      })
      .listen('.message.deleted', (event: { conversation_id: number, message_id: number }) => {
        forget(event.conversation_id, event.message_id)
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

  async function send(body: string, files: File[] = [], replyToId: number | null = null): Promise<void> {
    const id = activeId.value

    if (!id) {
      return
    }

    const { data } = await api.sendMessage(id, body, files, {}, replyToId)

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

  /**
   * Правит свою реплику.
   *
   * Ответ сервера кладём на место сразу, не дожидаясь эха: правка должна быть
   * видна тому, кто её сделал, ровно в тот момент, когда он нажал «готово».
   */
  async function edit(messageId: number, body: string): Promise<void> {
    const id = activeId.value

    if (!id) {
      return
    }

    const { data } = await api.editMessage(id, messageId, body)

    replace(id, data)
  }

  /** Убирает реплику у всех. */
  async function remove(messageId: number): Promise<void> {
    const id = activeId.value

    if (!id) {
      return
    }

    await api.deleteMessage(id, messageId)

    forget(id, messageId)
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
    dismiss,
    erase,
    loadOlder,
    send,
    edit,
    remove,
    announceTyping,
    markRead,
    writeTo,
  }
}