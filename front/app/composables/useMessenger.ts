import type { SendExtras } from '~/composables/useChatApi'
import type {
  ChatMessage,
  ChatPerson,
  Conversation,
  DeletionScope,
  MessageAbout,
  MessageReaction,
  QuotedMessage,
  Sending,
  ThreadMessage,
} from '~/types/chat'
import { UploadAbortedError, UploadError } from '~/utils/upload'

/**
 * Отправляемое прямо сейчас — одно на вкладку, а не на вызов composable.
 *
 * Здесь, а не в useState: и счётчик, и запросы — вещи, которые не переживают
 * перезагрузку страницы и не должны попадать в состояние, уезжающее с сервера.
 * Ключ — тот самый отрицательный id, под которым строка стоит в ленте.
 */
const transfers = new Map<number, AbortController>()

/** Свои неотправленные строки нумеруются вниз: настоящих id у них ещё нет. */
let nextLocalId = -1

/** Сколько сообщений приезжает за раз — столько же отдаёт сервер. */
const PAGE = 40

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
  const messages = useState<ThreadMessage[]>('chat.messages', () => [])
  const typing = useState<ChatPerson[]>('chat.typing', () => [])

  /** Закреплённое наверху разговора — последнее поднятое первым. */
  const pinned = useState<ChatMessage[]>('chat.pinned', () => [])

  /**
   * С какой реплики начинается непрочитанное.
   *
   * Запоминается на всё время, пока переписка открыта, хотя прочитанной она
   * становится сразу: отбивка «непрочитанные» должна остаться на месте, пока
   * человек читает, — иначе она исчезает в тот же миг, в который появилась.
   */
  const firstUnreadId = useState<number | null>('chat.first-unread', () => null)

  /**
   * Есть ли что догружать вверх и вниз.
   *
   * Вниз — не всегда «нет»: с поиска попадают в середину разговора, и под
   * перенесённой репликой лежит не конец ленты, а такой же догружаемый кусок.
   */
  const hasOlder = useState<boolean>('chat.has-older', () => false)
  const hasNewer = useState<boolean>('chat.has-newer', () => false)

  const active = computed(() => conversations.value.find(one => one.id === activeId.value) ?? null)

  /** Участники открытой переписки — по ним узнаются упоминания в тексте. */
  const participants = computed<ChatPerson[]>(() => active.value?.participants ?? [])

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
      .listen('.message.reacted', (event: { conversation_id: number, message_id: number, reactions: MessageReaction[] }) => {
        restack(event.conversation_id, event.message_id, event.reactions)
      })
      .listen('.message.pinned', (event: { conversation_id: number, message: ChatMessage, pinned: boolean }) => {
        repin(event.conversation_id, event.message, event.pinned)
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
    // Открытая лента, показанная до конца: в середине разговора, куда попали с
    // поиска, новому сообщению места нет — под ним ещё лежит непрочитанный кусок.
    const isOpen = activeId.value === conversationId && !hasNewer.value

    if (isOpen && mine) {
      // Своё приходит эхом раньше, чем ответ на сам запрос: вещание идёт без
      // очереди, а ответ ещё едет. Черновая строка уступает место настоящей
      // сразу — иначе снимок на мгновение виден дважды. Узнаётся она по составу:
      // своего ключа у отправки нет, а придумывать его пришлось бы вместе с
      // сервером, который его вернёт.
      const twin = messages.value.find(one => one.sending
        && !one.error
        && one.body === message.body
        && one.attachments.length === message.attachments.length)

      if (twin) {
        messages.value = messages.value.filter(one => one.id !== twin.id)
      }
    }

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

    if (!mine && activeId.value !== conversationId) {
      conversation.unread_count += 1
      unreadTotal.value += 1
    }

    resort()

    if (isOpen && !mine) {
      void markRead(conversationId)
    }
  }

  /**
   * Порядок списка: закреплённые сверху, дальше по свежести.
   *
   * Тот же порядок, что у сервера, — иначе после первого же пришедшего
   * сообщения список на экране разошёлся бы с тем, каким он приедет при
   * следующей загрузке.
   */
  function resort(): void {
    conversations.value = [...conversations.value].sort((left, right) => {
      if (left.is_pinned !== right.is_pinned) {
        return left.is_pinned ? -1 : 1
      }

      return stamp(right.last_message_at) - stamp(left.last_message_at)
    })
  }

  function stamp(iso: string | null): number {
    return iso ? new Date(iso).getTime() : 0
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
      pinned.value = pinned.value.map(one => (one.id === message.id ? message : one))
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

      // С полосы наверху удалённое уходит вместе с лентой: показывать там было
      // бы нечего.
      pinned.value = pinned.value.filter(one => one.id !== messageId)
    }

    const conversation = conversations.value.find(one => one.id === conversationId)

    // Удалили последнее сказанное — в списке надо показать предыдущее, а его у
    // вкладки нет. Это единственный случай, когда список перечитывается.
    if (conversation?.last_message?.id === messageId) {
      void refreshConversations()
    }
  }

  /** Отклики под репликой сменились — набором целиком, а не по одному. */
  function restack(conversationId: number, messageId: number, reactions: MessageReaction[]): void {
    if (activeId.value !== conversationId) {
      return
    }

    messages.value = messages.value.map(one => (one.id === messageId ? { ...one, reactions } : one))
  }

  /** Реплику подняли наверх или сняли оттуда. */
  function repin(conversationId: number, message: ChatMessage, isPinned: boolean): void {
    if (activeId.value !== conversationId) {
      return
    }

    messages.value = messages.value.map(one => (one.id === message.id
      ? { ...one, pinned_at: message.pinned_at }
      : one))

    pinned.value = isPinned
      ? [message, ...pinned.value.filter(one => one.id !== message.id)]
      : pinned.value.filter(one => one.id !== message.id)
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

  /* ---------- Личные отметки на разговоре ---------- */

  /**
   * Приглушает и возвращает звук.
   *
   * Отметка встаёт на месте, не дожидаясь ответа: она личная, менять её больше
   * некому, а нажавший «без звука» должен увидеть это сразу.
   */
  async function mute(conversationId: number, muted: boolean): Promise<void> {
    const conversation = conversations.value.find(one => one.id === conversationId)

    if (!conversation) {
      return
    }

    conversation.is_muted = muted

    try {
      await api.muteConversation(conversationId, muted)
    }
    catch (error) {
      conversation.is_muted = !muted

      throw error
    }
  }

  /** Поднимает разговор наверх списка и опускает обратно. */
  async function pinChat(conversationId: number, isPinned: boolean): Promise<void> {
    const conversation = conversations.value.find(one => one.id === conversationId)

    if (!conversation) {
      return
    }

    conversation.is_pinned = isPinned
    resort()

    try {
      await api.pinConversation(conversationId, isPinned)
    }
    catch (error) {
      conversation.is_pinned = !isPinned
      resort()

      throw error
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
    pinned.value = []

    const answer = await api.fetchMessages(id)

    messages.value = answer.data
    pinned.value = answer.meta.pinned
    firstUnreadId.value = answer.meta.first_unread_id
    hasOlder.value = answer.data.length >= PAGE
    hasNewer.value = false

    await markRead(id)

    listen(id)
  }

  /**
   * Открывает переписку на конкретной реплике — так попадают с поиска.
   *
   * Лента при этом читается с двух сторон: то, что было до, и то, что после.
   * Иначе под перенесённой репликой оставалась бы пустота, а прокрутка вниз
   * упиралась бы в неё же.
   */
  async function openAt(id: number, messageId: number): Promise<void> {
    if (activeId.value !== id) {
      closeThread()

      activeId.value = id
      typing.value = []
    }

    messages.value = []
    pinned.value = []

    const [before, after] = await Promise.all([
      api.fetchMessages(id, { before: messageId + 1 }),
      api.fetchMessages(id, { after: messageId }),
    ])

    messages.value = [...before.data, ...after.data]
    pinned.value = before.meta.pinned
    firstUnreadId.value = before.meta.first_unread_id
    hasOlder.value = before.data.length >= PAGE
    hasNewer.value = after.data.length >= PAGE

    await markRead(id)

    listen(id)
  }

  /** Возвращает ленту к концу разговора — из середины, куда попали с поиска. */
  async function returnToEnd(): Promise<void> {
    const id = activeId.value

    if (!id || !hasNewer.value) {
      return
    }

    const answer = await api.fetchMessages(id)

    messages.value = answer.data
    pinned.value = answer.meta.pinned
    hasOlder.value = answer.data.length >= PAGE
    hasNewer.value = false
  }

  /** Подписка на ленту — отдельно от чтения: открывают её двумя способами. */
  function listen(id: number): void {
    if (!$echo || thread) {
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
      .listen('.message.reacted', (event: { conversation_id: number, message_id: number, reactions: MessageReaction[] }) => {
        restack(event.conversation_id, event.message_id, event.reactions)
      })
      .listen('.message.pinned', (event: { conversation_id: number, message: ChatMessage, pinned: boolean }) => {
        repin(event.conversation_id, event.message, event.pinned)
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

    if (!id || !oldest || !hasOlder.value) {
      return
    }

    const { data } = await api.fetchMessages(id, { before: oldest.id })

    hasOlder.value = data.length >= PAGE
    messages.value = [...data, ...messages.value]
  }

  /** Догружает то, что было позже, — когда лента открыта в середине. */
  async function loadNewer(): Promise<void> {
    const id = activeId.value
    const newest = messages.value[messages.value.length - 1]

    if (!id || !newest || !hasNewer.value) {
      return
    }

    const { data } = await api.fetchMessages(id, { after: newest.id })

    hasNewer.value = data.length >= PAGE
    messages.value = [...messages.value, ...data]
  }

  /* ---------- Отправка ---------- */

  /**
   * Реплика встаёт в ленту сразу и уходит на сервер сама.
   *
   * Так делают все мессенджеры, и не ради красоты: файл на двадцать мегабайт
   * едет секунды, а то и минуты. Пока он едет, поле ввода свободно, снимки уже
   * видны, а сколько байт ушло — написано на самой реплике. Прежде страница
   * ждала ответа с заблокированной формой, и отправка тяжёлого выглядела так,
   * будто мессенджер повис.
   *
   * @param about Материал, с которого пишут: карточка для ленты и ссылка для
   *              сервера. Приходит с «Написать» на странице материала.
   */
  async function send(
    body: string,
    files: File[] = [],
    extras: SendExtras & { card?: MessageAbout | null } = {},
  ): Promise<void> {
    const id = activeId.value

    if (!id) {
      return
    }

    // Отправив из середины разговора, человек ждёт увидеть своё сообщение — а
    // оно ляжет в конец, которого сейчас на экране нет.
    if (hasNewer.value) {
      await returnToEnd()
    }

    const previews = files.map(file => URL.createObjectURL(file))

    const pending: ThreadMessage = {
      id: nextLocalId--,
      conversation_id: id,
      kind: 'text',
      body: body || null,
      author: me(),
      // Снимки берутся из выбранных файлов: показать их надо сейчас, а не когда
      // сервер вернёт свои адреса. Номера — из того же убывающего счётчика, что
      // и у самих строк: просмотрщик ищет вложение по номеру во всей ленте, и
      // двух «минус первых» в ней быть не должно.
      attachments: files.map((file, at) => ({
        id: nextLocalId--,
        name: file.name,
        mime_type: file.type || null,
        size: file.size,
        opens_inline: file.type.startsWith('image/') || file.type.startsWith('video/'),
        url: previews[at] ?? null,
        is_voice: Boolean(extras.voice) && at === 0,
        duration_ms: at === 0 ? extras.voice?.duration_ms ?? null : null,
        waveform: at === 0 ? extras.voice?.waveform ?? [] : [],
      })),
      created_at: new Date().toISOString(),
      edited_at: null,
      reply_to: quote(extras.replyToId ?? null),
      // Карточка встаёт над репликой сразу, вместе с ней: то же, что увидит
      // адресат, — и то же, что человек видел над полем ввода.
      about: extras.card ?? null,
      forwarded: null,
      reactions: [],
      pinned_at: null,
      sending: true,
      progress: 0,
      files,
      previews,
      aboutRef: extras.about ?? null,
      mentions: extras.mentions ?? [],
      voice: extras.voice ?? null,
    }

    messages.value = [...messages.value, pending]

    await deliver(pending.id)
  }

  /** Повторяет сорвавшуюся отправку тем же составом. */
  async function resend(localId: number): Promise<void> {
    await deliver(localId)
  }

  /**
   * Убирает уходящую строку: обрывает передачу, если она ещё идёт, и снимает
   * строку с ленты.
   *
   * Одно действие на два случая — «отменить» на полпути и «убрать» у
   * сорвавшейся: и там, и там человек говорит «не надо». Прерванный запрос
   * сервер до логики не доводит, поэтому отменённое не появится ни у кого.
   */
  function cancelSending(localId: number): void {
    const transfer = transfers.get(localId)

    if (transfer) {
      // Строку с ленты уберёт сам deliver, поймав отмену.
      transfer.abort()

      return
    }

    const pending = messages.value.find(one => one.id === localId)

    messages.value = messages.value.filter(one => one.id !== localId)
    release(pending?.previews ?? [], 0)
  }

  /**
   * Один заход отправки: байты, прогресс и что делать с исходом.
   *
   * Своё сообщение может прийти эхом от сокета раньше, чем ответ на сам запрос,
   * — тогда настоящая строка уже в ленте, и черновой остаётся только исчезнуть.
   */
  async function deliver(localId: number): Promise<void> {
    const pending = messages.value.find(one => one.id === localId)

    if (!pending?.sending) {
      return
    }

    const conversationId = pending.conversation_id
    const controller = new AbortController()

    transfers.set(localId, controller)
    patch(localId, { progress: 0, error: undefined })

    try {
      const { data } = await api.sendMessage(
        conversationId,
        pending.body ?? '',
        pending.files ?? [],
        {
          signal: controller.signal,
          onProgress: ({ percent }) => patch(localId, { progress: percent }),
        },
        {
          replyToId: pending.reply_to?.id ?? null,
          about: pending.aboutRef ?? null,
          mentions: pending.mentions ?? [],
          voice: pending.voice ?? null,
        },
      )

      const arrived = messages.value.some(one => one.id === data.id)

      messages.value = messages.value.flatMap(one => (one.id === localId
        ? (arrived ? [] : [data])
        : [one]))

      release(pending.previews ?? [])
      promote(conversationId, data)
    }
    catch (error) {
      if (error instanceof UploadAbortedError) {
        messages.value = messages.value.filter(one => one.id !== localId)
        release(pending.previews ?? [], 0)

        return
      }

      patch(localId, { error: reasonFor(error) })
    }
    finally {
      transfers.delete(localId)
    }
  }

  /** Правит черновую строку на месте, не задевая остальные. */
  function patch(localId: number, changes: Partial<Sending>): void {
    messages.value = messages.value.map(one => (one.id === localId ? { ...one, ...changes } : one))
  }

  /** Поднимает переписку наверх списка и подписывает её последним сказанным. */
  function promote(conversationId: number, message: ChatMessage): void {
    const conversation = conversations.value.find(one => one.id === conversationId)

    if (!conversation) {
      return
    }

    conversation.last_message = message
    conversation.last_message_at = message.created_at
    resort()
  }

  /**
   * Освобождает предпросмотр — но не в тот же миг.
   *
   * Снимок с сервера к этой секунде ещё качается, и отняв адрес сразу, мы
   * мигнули бы пустотой на месте только что отправленного.
   */
  function release(urls: string[], after = 15_000): void {
    if (urls.length === 0) {
      return
    }

    window.setTimeout(() => urls.forEach(url => URL.revokeObjectURL(url)), after)
  }

  /** Что сказать про сорвавшуюся отправку. */
  function reasonFor(error: unknown): string {
    if (error instanceof UploadError) {
      const first = Object.values(error.data?.errors ?? {})[0]?.[0]

      return first ?? error.message
    }

    return 'Не удалось отправить'
  }

  /** Себя — участником, каким его отдаёт сервер. */
  function me(): ChatPerson | null {
    const person = user.value

    if (!person) {
      return null
    }

    return {
      id: person.id,
      name: person.name,
      short_name: person.name,
      email: person.email,
      avatar_url: person.avatar_url ?? null,
    }
  }

  /** Цитата для черновой строки — собирается из того, что уже в ленте. */
  function quote(replyToId: number | null): QuotedMessage | null {
    if (replyToId === null) {
      return null
    }

    const source = messages.value.find(one => one.id === replyToId)

    return {
      id: replyToId,
      deleted: false,
      author: source?.author ?? null,
      excerpt: source?.body ?? (source?.attachments.length ? 'Вложение' : null),
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

  /** Убирает выделенное — всё разом или ничего. */
  async function removeMany(messageIds: number[]): Promise<void> {
    const id = activeId.value

    if (!id || messageIds.length === 0) {
      return
    }

    await api.deleteMessages(id, messageIds)

    messageIds.forEach(messageId => forget(id, messageId))
  }

  /**
   * Ставит, меняет и снимает отклик.
   *
   * Знак встаёт под репликой сразу, до ответа сервера: нажатие по отклику —
   * самое частое движение во всём мессенджере, и полсекунды ожидания на нём
   * чувствуются сильнее, чем где угодно ещё. Сорвалось — возвращаем как было.
   */
  async function react(messageId: number, emoji: string): Promise<void> {
    const id = activeId.value
    const target = messages.value.find(one => one.id === messageId)

    if (!id || !target) {
      return
    }

    const was = target.reactions

    restack(id, messageId, guessReactions(was, emoji))

    try {
      const { data } = await api.react(id, messageId, emoji)

      restack(id, messageId, data.reactions)
    }
    catch (error) {
      restack(id, messageId, was)

      throw error
    }
  }

  /**
   * Каким станет набор откликов, если нажать этот знак.
   *
   * Повторяет правило сервера: тем же знаком отклик снимается, другим —
   * заменяется. Настоящий набор приедет ответом и встанет на место этого; здесь
   * важно лишь, чтобы промежуточная догадка не отличалась от него на глаз.
   */
  function guessReactions(current: MessageReaction[], emoji: string): MessageReaction[] {
    const mine = user.value?.id

    if (!mine) {
      return current
    }

    const withoutMe = current
      .map(one => ({ ...one, user_ids: one.user_ids.filter(id => id !== mine) }))
      .map(one => ({ ...one, count: one.user_ids.length }))
      .filter(one => one.count > 0)

    const had = current.find(one => one.user_ids.includes(mine))?.emoji === emoji

    if (had) {
      return withoutMe
    }

    const standing = withoutMe.find(one => one.emoji === emoji)

    return standing
      ? withoutMe.map(one => (one.emoji === emoji
        ? { ...one, count: one.count + 1, user_ids: [...one.user_ids, mine] }
        : one))
      : [...withoutMe, { emoji, count: 1, user_ids: [mine], people: [] }]
  }

  /** Поднимает реплику наверх переписки и снимает оттуда. */
  async function pinMessage(messageId: number, isPinned: boolean): Promise<void> {
    const id = activeId.value

    if (!id) {
      return
    }

    const { data } = isPinned
      ? await api.pinMessage(id, messageId)
      : await api.unpinMessage(id, messageId)

    repin(id, data, isPinned)
  }

  /**
   * Пересылает выделенное в другой разговор.
   *
   * Список переписок после этого перечитывается: пересланное стало последним
   * сказанным в получателе, и его строчка обязана подняться наверх — даже когда
   * сокет-сервер не поднят.
   */
  async function forward(messageIds: number[], toId: number): Promise<void> {
    const id = activeId.value

    if (!id || messageIds.length === 0) {
      return
    }

    await api.forwardMessages(id, messageIds, toId)
    await refreshConversations()
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
      conversation.unread_mentions = 0
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
    pinned.value = []
    firstUnreadId.value = null
    hasOlder.value = false
    hasNewer.value = false
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
    online,
    messages,
    typing,
    pinned,
    firstUnreadId,
    hasOlder,
    hasNewer,
    activeId,
    active,
    participants,
    isOnline,
    connect,
    refreshConversations,
    open,
    openAt,
    returnToEnd,
    closeThread,
    dismiss,
    erase,
    mute,
    pinChat,
    loadOlder,
    loadNewer,
    send,
    resend,
    cancelSending,
    edit,
    remove,
    removeMany,
    react,
    pinMessage,
    forward,
    announceTyping,
    markRead,
    writeTo,
  }
}
