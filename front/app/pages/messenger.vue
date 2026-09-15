<script setup lang="ts">
import type { MenuAction } from '~/components/chat/BubbleMenu.vue'
import type {
  ChatMessage,
  Conversation,
  MaterialRef,
  MessageAbout,
  MessageAttachment,
  ThreadMessage,
  VoiceNumbers,
} from '~/types/chat'
import { plainMessageText } from '~/utils/messageText'

// `fills`: страница занимает ровно экран и не растёт с содержимым — оболочка
// объявляет себя в `100dvh` и снимает нижний отступ.
definePageMeta({ middleware: 'auth', fills: true })
useHead({ title: 'Сообщения' })

const { user } = useAuth()
const { confirm } = useAppDialog()
const api = useChatApi()
const messenger = useMessenger()
const route = useRoute()
const router = useRouter()

const {
  conversations,
  messages,
  typing,
  pinned,
  firstUnreadId,
  hasOlder,
  hasNewer,
  activeId,
  active,
  participants,
} = messenger

const me = computed(() => user.value?.id ?? null)

/* ---------- Куда ведёт адрес ---------- */

/*
 * Переписка выбирается адресом: ?id=12.
 *
 * Так на неё можно сослаться — из карточки ответственного за курс, из ответа
 * консультанта, — и так работает кнопка «назад» в браузере, которой на двух
 * панелях пользуются постоянно.
 */
const thread = ref<{ scrollToEnd: (smooth?: boolean) => Promise<void>, scrollTo: (id: number, smooth?: boolean) => Promise<boolean> } | null>(null)

const highlighted = ref<number | null>(null)
const atBottom = ref(true)

/**
 * Сколько чужих реплик пришло, пока человек читал прошлое.
 *
 * Своя цифра, а не та, что в списке: открытая переписка отмечается прочитанной
 * сразу, и её счётчик непрочитанного к этому времени уже погашен. А вопрос
 * «сколько я пропустил, пока листал вверх» никуда не делся — на него и отвечает
 * цифра на кнопке «вниз».
 */
const missed = ref(0)

async function openConversation(id: number, atMessage: number | null = null): Promise<void> {
  missed.value = 0

  if (atMessage) {
    await messenger.openAt(id, atMessage)
    await thread.value?.scrollTo(atMessage, false)
    flash(atMessage)

    return
  }

  await messenger.open(id)

  /*
   * Открываем на последнем сказанном — всегда.
   *
   * Была попытка умнее: вставать на первом непрочитанном, как в телеграме. На
   * деле это значило открыть переписку и увидеть позавчерашнее, а сегодняшнее
   * искать прокруткой вниз — переписку открывают, чтобы прочесть последнее и
   * ответить. Где кончается прочитанное, по-прежнему видно: в ленте стоит
   * отбивка «Непрочитанные», и до неё долистывают вверх, когда это правда
   * нужно.
   */
  await thread.value?.scrollToEnd()
}

function select(id: number): void {
  void router.push({ query: { id } })
}

onMounted(async () => {
  await messenger.connect()

  // ?write=7 — «написать вот этому человеку»: так сюда ведут карточки
  // ответственных за курс и совет консультанта.
  const addressee = Number(route.query.write)

  if (addressee) {
    const asked = materialFrom(route.query.about)
    const id = await messenger.writeTo(addressee)

    await noteMaterial(asked, id)
    await router.replace({ query: { id } })
    await openConversation(id)

    return
  }

  const wanted = Number(route.query.id)

  if (wanted) {
    await openConversation(wanted)
  }
})

watch(() => route.query.id, async (value) => {
  const wanted = Number(value)

  if (!wanted) {
    // Адрес без переписки — значит, вернулись к списку. На телефоне панель одна,
    // и без этого кнопка «назад» меняла адрес, не закрывая ленту.
    messenger.closeThread()

    return
  }

  if (wanted !== activeId.value) {
    await openConversation(wanted)
  }
})

/*
 * Открытый разговор забирает телефон целиком.
 *
 * Нижняя полоса разделов при этом уходит — как во всяком мессенджере. Она съедает
 * те самые восемьдесят точек, которых не хватает ленте, и ставит второй ряд
 * кнопок вплотную под полем ввода: палец, промахнувшись мимо «отправить», уходит
 * в другой раздел. Убирает её сама полоса и только на узком экране — на планшете
 * список и лента стоят рядом, и разделы там никому не мешают.
 */
const { hideDock, restoreChrome } = useShellChrome()

watch(activeId, (id) => {
  hideDock.value = id !== null
}, { immediate: true })

onBeforeUnmount(() => {
  restoreChrome()
  messenger.closeThread()
})

/*
 * Открытой переписки не стало — её удалили у всех, пока мы в неё смотрели, либо
 * убрали мы сами. Возвращаемся к списку: адрес указывает на разговор, которого
 * больше нет, и кнопка «назад» привела бы обратно в пустоту.
 */
watch(conversations, (list) => {
  const shown = Number(route.query.id)

  if (shown && !list.some(one => one.id === shown)) {
    void router.replace({ query: {} })
  }
})

/* ---------- Материал, с которого пришли ---------- */

/*
 * С карточки ответственного в документе или курсе уходят «написать», и без
 * этого адресат читает вопрос, не понимая, о чём он. Адрес несёт только вид и
 * номер — `?about=document:12`, — а название и ссылку отдаёт сервер.
 */
const material = ref<MessageAbout | null>(null)
const materialRef = ref<MaterialRef | null>(null)
const materialFor = ref<number | null>(null)

const KINDS: MessageAbout['kind'][] = ['course', 'lesson', 'document', 'handbook']

function materialFrom(value: unknown): MaterialRef | null {
  const [kind, id] = String(value ?? '').split(':')

  return KINDS.includes(kind as MessageAbout['kind']) && Number(id) > 0
    ? { kind: kind as MessageAbout['kind'], id: Number(id) }
    : null
}

/**
 * Материал выброшен или закрыт для спрашивающего — письмо всё равно уходит,
 * просто без карточки: разговор с ответственным важнее подписи над ним.
 */
async function noteMaterial(asked: MaterialRef | null, conversationId: number): Promise<void> {
  if (!asked) {
    return
  }

  try {
    material.value = (await api.fetchAbout(asked)).data
    materialRef.value = asked
    materialFor.value = conversationId
  }
  catch {
    dropMaterial()
  }
}

/** Карточка стоит над полем ввода, пока открыт тот разговор, ради которого пришли. */
const composingAbout = computed(() =>
  (material.value !== null && materialFor.value === activeId.value ? material.value : null),
)

function dropMaterial(): void {
  material.value = null
  materialRef.value = null
  materialFor.value = null
}

/* ---------- Ответ и правка ---------- */

/**
 * На что отвечаем и что переписываем. Одновременно ни то ни другое: правка
 * своей реплики и ответ на чужую — разные намерения, и одно поле ввода не может
 * означать оба сразу. Начатое второе отменяет первое.
 */
const replyTo = ref<ChatMessage | null>(null)
const editing = ref<ChatMessage | null>(null)

function startReply(message: ChatMessage): void {
  editing.value = null
  replyTo.value = message
}

function startEditing(message: ChatMessage): void {
  replyTo.value = null
  editing.value = message
}

function cancelComposing(): void {
  replyTo.value = null
  editing.value = null
}

/* ---------- Отправка ---------- */

async function onSend(payload: { body: string, files: File[], mentions: number[], voice: VoiceNumbers | null }): Promise<void> {
  // Карточка достаётся первой реплике — той, ради которой сюда пришли:
  // повторять её у каждого следующего сообщения незачем, разговор уже начат.
  const card = composingAbout.value
  const ref = materialRef.value

  if (card) {
    dropMaterial()
  }

  const answering = replyTo.value?.id ?? null
  replyTo.value = null

  // Ошибку отправки показывает сама реплика, вместе с «повторить», поэтому
  // ждать здесь нечего: send не отказывает.
  void messenger.send(payload.body, payload.files, {
    replyToId: answering,
    about: card ? ref : null,
    card,
    mentions: payload.mentions,
    voice: payload.voice,
  })

  await thread.value?.scrollToEnd(true)
}

async function onSave(body: string): Promise<void> {
  const message = editing.value

  if (!message) {
    return
  }

  editing.value = null
  await messenger.edit(message.id, body)
}

/*
 * Новое сообщение в открытой ленте.
 *
 * Едем вниз только если человек и так внизу: иначе лента дёргается под руками у
 * того, кто читает прошлое. Не поехали — считаем пропущенное, чтобы написать
 * цифру на кнопке «вниз».
 */
watch(() => messages.value.length, async (now, was) => {
  if (atBottom.value) {
    missed.value = 0
    await thread.value?.scrollToEnd(true)

    return
  }

  const last = messages.value[messages.value.length - 1]

  if (now > was && last && last.author?.id !== me.value) {
    missed.value += 1
  }
})

watch(atBottom, (bottom) => {
  if (bottom) {
    missed.value = 0
  }
})

/* ---------- Перескок ---------- */

/**
 * Переносит к реплике — и сходит за ней, если её в ленте ещё нет.
 *
 * Цитата или находка поиска может быть годичной давности: догружать всё, что
 * было между, можно очень долго, а открыть ленту сразу вокруг нужного места —
 * два запроса.
 */
async function jumpTo(messageId: number, conversationId = activeId.value): Promise<void> {
  if (!conversationId) {
    return
  }

  if (conversationId !== activeId.value) {
    await router.push({ query: { id: conversationId } })
    await openConversation(conversationId, messageId)

    return
  }

  if (await thread.value?.scrollTo(messageId)) {
    flash(messageId)

    return
  }

  await openConversation(conversationId, messageId)
}

/** Подсветка гаснет сама: она отвечает на «куда меня перенесло». */
function flash(messageId: number): void {
  highlighted.value = messageId

  setTimeout(() => {
    if (highlighted.value === messageId) {
      highlighted.value = null
    }
  }, 1600)
}

/* ---------- Выделение ---------- */

const selected = ref<number[]>([])
const selecting = computed(() => selected.value.length > 0)

function toggleSelect(message: ThreadMessage): void {
  selected.value = selected.value.includes(message.id)
    ? selected.value.filter(id => id !== message.id)
    : [...selected.value, message.id]
}

function clearSelection(): void {
  selected.value = []
}

/** Удалять можно, если каждое выделенное можно удалить: сервер решает так же. */
const canDeleteSelected = computed(() => selected.value.every((id) => {
  const message = messages.value.find(one => one.id === id)

  return message !== undefined && canDelete(message)
}))

async function removeSelected(): Promise<void> {
  const count = selected.value.length

  const confirmed = await confirm({
    title: count === 1 ? 'Удалить сообщение?' : `Удалить ${count} сообщений?`,
    message: 'У всех участников переписки они исчезнут — отменить это будет нельзя.',
    confirmLabel: 'Удалить',
    danger: true,
  })

  if (!confirmed) {
    return
  }

  await messenger.removeMany(selected.value)
  clearSelection()
}

/**
 * Копирует выделенное — текстом, в том порядке, в каком это говорили.
 *
 * С именами и без разметки: копируют обычно затем, чтобы переслать наружу — в
 * почту или в отчёт, — и там ни звёздочки, ни «кто-то сказал» не нужны.
 */
async function copySelected(): Promise<void> {
  const text = messages.value
    .filter(one => selected.value.includes(one.id))
    .map(one => `${one.author?.short_name ?? 'Бывший сотрудник'}: ${plainMessageText(one.body ?? '')}`)
    .join('\n')

  try {
    await navigator.clipboard.writeText(text)
  }
  catch {
    // Буфер закрыт настройками браузера. Сказать об этом нечем — окно об ошибке
    // копирования раздражает сильнее, чем несработавшая кнопка.
  }

  clearSelection()
}

/* ---------- Меню реплики ---------- */

const menuFor = ref<ThreadMessage | null>(null)
const menuAt = ref({ x: 0, y: 0 })

/** Править можно только своё и только сказанное словами. */
function canEdit(message: ThreadMessage): boolean {
  return message.kind === 'text' && message.author?.id === me.value && !message.attachments?.some(f => f.is_voice)
}

/** Удалять — своё, а в группе ещё и чужое, если группу завёл ты. */
function canDelete(message: ThreadMessage): boolean {
  return message.kind === 'text' && (message.author?.id === me.value || active.value?.is_owner === true)
}

/** Закреплять в группе может только заведший её: полоса наверху одна на всех. */
const canPin = computed(() => active.value !== null && (!active.value.is_group || active.value.is_owner))

const menuActions = computed<MenuAction[]>(() => {
  const message = menuFor.value

  if (!message) {
    return []
  }

  const actions: MenuAction[] = [
    { key: 'reply', label: 'Ответить' },
    { key: 'forward', label: 'Переслать' },
    { key: 'select', label: 'Выбрать' },
  ]

  if (message.body) {
    actions.push({ key: 'copy', label: 'Скопировать текст' })
  }

  if (canPin.value) {
    actions.push({ key: 'pin', label: message.pinned_at ? 'Открепить' : 'Закрепить' })
  }

  if (canEdit(message)) {
    actions.splice(1, 0, { key: 'edit', label: 'Изменить' })
  }

  if (canDelete(message)) {
    actions.push({ key: 'delete', label: 'Удалить у всех', danger: true })
  }

  return actions
})

function openMenu(message: ThreadMessage, at: { x: number, y: number }): void {
  menuFor.value = message

  // Нажали по кнопке «⋯» — она сообщает нули: ставим меню там, где сама кнопка.
  menuAt.value = at.x || at.y ? at : lastPointer
}

/** Где в последний раз был указатель — на случай меню, вызванного кнопкой. */
let lastPointer = { x: 0, y: 0 }

function trackPointer(event: PointerEvent): void {
  lastPointer = { x: event.clientX, y: event.clientY }
}

onMounted(() => document.addEventListener('pointerdown', trackPointer, true))
onBeforeUnmount(() => document.removeEventListener('pointerdown', trackPointer, true))

async function onMenuPick(key: string): Promise<void> {
  const message = menuFor.value

  menuFor.value = null

  if (!message) {
    return
  }

  if (key === 'reply') {
    startReply(message)
  }
  else if (key === 'edit') {
    startEditing(message)
  }
  else if (key === 'select') {
    selected.value = [message.id]
  }
  else if (key === 'forward') {
    selected.value = [message.id]
    forwarding.value = true
  }
  else if (key === 'copy') {
    try {
      await navigator.clipboard.writeText(plainMessageText(message.body ?? ''))
    }
    catch {
      // Буфер закрыт настройками браузера — молча обходимся.
    }
  }
  else if (key === 'pin') {
    await messenger.pinMessage(message.id, !message.pinned_at)
  }
  else if (key === 'delete') {
    await removeOne(message)
  }
}

/** Чем этот человек уже откликнулся: в подсказке такой знак отмечен. */
function myReactionIn(message: ThreadMessage): string | null {
  return message.reactions?.find(one => me.value !== null && one.user_ids.includes(me.value))?.emoji ?? null
}

async function reactFromMenu(emoji: string): Promise<void> {
  const message = menuFor.value

  menuFor.value = null

  if (message) {
    await messenger.react(message.id, emoji)
  }
}

async function removeOne(message: ThreadMessage): Promise<void> {
  const confirmed = await confirm({
    title: 'Удалить сообщение?',
    message: 'У всех участников переписки оно исчезнет — отменить это будет нельзя.',
    confirmLabel: 'Удалить',
    danger: true,
  })

  if (!confirmed) {
    return
  }

  await messenger.remove(message.id)

  // Правили или отвечали именно на неё — теперь не на что.
  if (editing.value?.id === message.id || replyTo.value?.id === message.id) {
    cancelComposing()
  }
}

/* ---------- Пересылка ---------- */

const forwarding = ref(false)

async function forwardTo(conversationId: number): Promise<void> {
  const ids = [...selected.value]

  forwarding.value = false
  clearSelection()

  await messenger.forward(ids, conversationId)
}

/* ---------- Меню переписки ---------- */

const chatMenuFor = ref<Conversation | null>(null)
const chatMenuAt = ref({ x: 0, y: 0 })

const chatMenuActions = computed<MenuAction[]>(() => {
  const conversation = chatMenuFor.value

  if (!conversation) {
    return []
  }

  const actions: MenuAction[] = [
    { key: 'pin', label: conversation.is_pinned ? 'Открепить' : 'Закрепить сверху' },
    { key: 'mute', label: conversation.is_muted ? 'Включить уведомления' : 'Без уведомлений' },
  ]

  if (conversation.is_group) {
    actions.push({ key: 'leave', label: 'Выйти из группы' })
  }

  actions.push({ key: 'clear', label: 'Удалить у себя', danger: true })

  if (!conversation.is_group || conversation.is_owner) {
    actions.push({
      key: 'erase',
      label: conversation.is_group ? 'Удалить группу у всех' : 'Удалить у всех',
      danger: true,
    })
  }

  return actions
})

function openChatMenu(conversation: Conversation, at: { x: number, y: number }): void {
  chatMenuFor.value = conversation
  chatMenuAt.value = at.x || at.y ? at : lastPointer
}

async function onChatMenuPick(key: string): Promise<void> {
  const conversation = chatMenuFor.value

  chatMenuFor.value = null

  if (!conversation) {
    return
  }

  if (key === 'pin') {
    await messenger.pinChat(conversation.id, !conversation.is_pinned)
  }
  else if (key === 'mute') {
    await messenger.mute(conversation.id, !conversation.is_muted)
  }
  else if (key === 'leave') {
    await leaveGroup(conversation)
  }
  else if (key === 'clear') {
    await eraseChat(conversation, 'mine')
  }
  else if (key === 'erase') {
    await eraseChat(conversation, 'everyone')
  }
}

async function leaveGroup(conversation: Conversation): Promise<void> {
  const confirmed = await confirm({
    title: 'Выйти из группы?',
    message: 'Переписка останется у остальных, а ваши сообщения — в ленте.',
    confirmLabel: 'Выйти',
    danger: true,
  })

  if (!confirmed) {
    return
  }

  await api.leaveConversation(conversation.id)
  messenger.dismiss(conversation.id)
}

async function eraseChat(conversation: Conversation, scope: 'mine' | 'everyone'): Promise<void> {
  const what = conversation.is_group ? 'группу' : 'переписку'

  const confirmed = scope === 'mine'
    ? await confirm({
        title: `Удалить ${what} у себя?`,
        message: 'У остальных она останется, к вам вернётся с новым сообщением — но уже без прошлого.',
        confirmLabel: 'Удалить у себя',
        danger: true,
      })
    : await confirm({
        title: conversation.is_group ? 'Удалить группу у всех?' : 'Удалить переписку у обоих?',
        message: 'Сообщения и приложенные файлы исчезнут навсегда — отменить это будет нельзя.',
        confirmLabel: 'Удалить у всех',
        danger: true,
      })

  if (!confirmed) {
    return
  }

  await messenger.erase(conversation.id, scope)
}

/* ---------- Состав группы ---------- */

const managing = ref(false)

watch(activeId, () => {
  managing.value = false
  searching.value = false
  clearSelection()
  cancelComposing()
})

async function rename(title: string): Promise<void> {
  if (!activeId.value) {
    return
  }

  await api.renameConversation(activeId.value, title)
  await messenger.refreshConversations()
}

async function invite(personId: number): Promise<void> {
  if (!activeId.value) {
    return
  }

  await api.addParticipants(activeId.value, [personId])
  await messenger.refreshConversations()
}

async function expel(personId: number): Promise<void> {
  if (!activeId.value) {
    return
  }

  await api.removeParticipant(activeId.value, personId)
  await messenger.refreshConversations()
}

/* ---------- Поиск по ленте ---------- */

const searching = ref(false)

/* ---------- Новая переписка ---------- */

const composing = ref(false)

async function startDirect(personId: number): Promise<void> {
  composing.value = false

  const id = await messenger.writeTo(personId)

  await messenger.refreshConversations()
  select(id)
}

async function startGroup(title: string, personIds: number[]): Promise<void> {
  composing.value = false

  const { data } = await api.startGroup(title, personIds)

  await messenger.refreshConversations()
  select(data.id)
}

/* ---------- Просмотр вложений ---------- */

/** Просматриваемое ищется во всей ленте: из открытого снимка листают соседние. */
const viewable = computed(() => messages.value
  .flatMap(one => one.attachments ?? [])
  .filter(file => !file.is_voice
    && (file.mime_type?.startsWith('image/') === true || file.mime_type?.startsWith('video/') === true)))

const viewingId = ref<number | null>(null)

function view(file: MessageAttachment): void {
  viewingId.value = file.id
}

function stepViewer(by: number): void {
  const at = viewable.value.findIndex(one => one.id === viewingId.value)
  const next = viewable.value[at + by]

  if (next) {
    viewingId.value = next.id
  }
}

/* ---------- Голосовые ---------- */

/** Играет не больше одной записи: две одновременно не слушают. */
const playingVoice = ref<number | null>(null)

// Ушли из переписки — звук с собой не уносим.
watch(activeId, () => {
  playingVoice.value = null
})

/* ---------- Клавиатура ---------- */

function onPageKey(event: KeyboardEvent): void {
  if (event.key !== 'Escape') {
    return
  }

  if (selecting.value) {
    clearSelection()
  }
  else if (searching.value) {
    searching.value = false
  }
}

onMounted(() => document.addEventListener('keydown', onPageKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onPageKey))

/** Нажали по упоминанию — открываем переписку с этим человеком. */
async function writeToMentioned(personId: number): Promise<void> {
  if (personId === me.value) {
    return
  }

  select(await messenger.writeTo(personId))
}
</script>

<template>
  <section class="messenger" :class="{ 'messenger--open': activeId !== null }">
    <!-- Слева переписки, справа лента. На узком экране показывается одна из
         двух: список, пока никто не выбран, и лента, когда выбран. -->
    <ChatList
      class="messenger__list"
      :conversations="conversations"
      :active-id="activeId"
      :me="me"
      :online-ids="messenger.online.value"
      @open="select"
      @menu="openChatMenu"
      @compose="composing = true"
      @write-to="writeToMentioned"
      @jump="(conversationId, messageId) => jumpTo(messageId, conversationId)"
    />

    <div class="pane">
      <template v-if="active">
        <ChatSelectionBar
          v-if="selecting"
          :count="selected.length"
          :can-delete="canDeleteSelected"
          @close="clearSelection"
          @forward="forwarding = true"
          @copy="copySelected"
          @remove="removeSelected"
        />

        <ChatHeader
          v-else
          :conversation="active"
          :typing="typing"
          :online="messenger.isOnline(active.companion?.id)"
          :managing="managing"
          @back="router.push({ query: {} })"
          @search="searching = !searching"
          @toggle-crew="managing = !managing"
          @menu="openChatMenu(active, $event)"
        />

        <ChatSearchBar
          v-if="searching"
          :conversation-id="active.id"
          @close="searching = false"
          @jump="jumpTo"
        />

        <ChatPinnedBar
          v-if="pinned.length"
          :messages="pinned"
          :can-unpin="canPin"
          @jump="jumpTo"
          @unpin="messenger.pinMessage($event, false)"
        />

        <ChatCrew
          v-if="managing && active.is_group"
          :conversation="active"
          :me="me"
          :online-ids="messenger.online.value"
          @rename="rename"
          @invite="invite"
          @expel="expel"
        />

        <div class="pane__thread">
          <ChatThread
            ref="thread"
            :messages="messages"
            :people="participants"
            :me="me"
            :is-group="active.is_group"
            :first-unread-id="firstUnreadId"
            :has-older="hasOlder"
            :has-newer="hasNewer"
            :highlighted="highlighted"
            :selecting="selecting"
            :selected="selected"
            :readers="participants"
            :playing-voice="playingVoice"
            :load-older="messenger.loadOlder"
            :load-newer="messenger.loadNewer"
            @reply="startReply"
            @menu="openMenu"
            @react="(message, emoji) => messenger.react(message.id, emoji)"
            @toggle-select="toggleSelect"
            @jump="jumpTo"
            @open-file="view"
            @mention="writeToMentioned"
            @play-voice="playingVoice = $event"
            @pause-voice="playingVoice = null"
            @retry="messenger.resend"
            @cancel="messenger.cancelSending"
            @at-bottom="atBottom = $event"
          />

          <!-- Кнопка «вниз» появляется, когда человек ушёл от конца ленты: без
               неё возвращаться к последнему сообщению приходится прокруткой. -->
          <Transition name="pop">
            <button
              v-if="!atBottom || hasNewer"
              type="button"
              class="down"
              aria-label="К последним сообщениям"
              @click="hasNewer ? messenger.returnToEnd().then(() => thread?.scrollToEnd()) : thread?.scrollToEnd(true)"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path
                  d="M6 9l6 6 6-6"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  fill="none"
                />
              </svg>
              <span v-if="missed" class="down__count">{{ missed > 99 ? '99+' : missed }}</span>
            </button>
          </Transition>
        </div>

        <ChatComposer
          :conversation-id="active.id"
          :people="participants"
          :me="me"
          :reply-to="replyTo"
          :editing="editing"
          :about="composingAbout"
          @send="onSend"
          @save="onSave"
          @cancel-composing="cancelComposing"
          @drop-about="dropMaterial"
          @typing="messenger.announceTyping"
        />
      </template>

      <UiEmptyState
        v-else
        title="Выберите переписку"
        description="Слева — те, с кем вы уже говорили. Кнопка «Написать» заведёт новую."
      />
    </div>

    <ChatBubbleMenu
      v-if="menuFor"
      :at="menuAt"
      :actions="menuActions"
      :mine="myReactionIn(menuFor)"
      :can-react="menuFor.kind === 'text' && !menuFor.sending"
      @pick="onMenuPick"
      @react="reactFromMenu"
      @close="menuFor = null"
    />

    <ChatBubbleMenu
      v-if="chatMenuFor"
      :at="chatMenuAt"
      :actions="chatMenuActions"
      :mine="null"
      :can-react="false"
      @pick="onChatMenuPick"
      @react="() => {}"
      @close="chatMenuFor = null"
    />

    <ChatViewer
      v-if="viewingId !== null"
      :files="viewable"
      :file-id="viewingId"
      @close="viewingId = null"
      @step="stepViewer"
    />

    <ChatForwardSheet
      v-if="forwarding && activeId"
      :conversations="conversations"
      :from-id="activeId"
      :count="selected.length"
      @pick="forwardTo"
      @close="forwarding = false"
    />

    <ChatComposeSheet
      v-if="composing"
      @direct="startDirect"
      @group="startGroup"
      @close="composing = false"
    />
  </section>
</template>

<style scoped>
/*
 * Две панели: список и лента. Высота считается от экрана, потому что лента
 * прокручивается сама — страница при этом стоит на месте, иначе поле ввода
 * уезжало бы вверх вместе с разговором.
 */
.messenger {
  display: grid;
  /* `minmax(0, …)` у обеих колонок: без нуля колонка не ужимается меньше своего
     содержимого, и одна длинная ссылка растягивает её вместе со всей страницей
     за край экрана. */
  grid-template-columns: minmax(0, 21rem) minmax(0, 1fr);
  gap: 1rem;
  /* Ровно то, что осталось от экрана: оболочка страницы объявлена в высоту
     экрана и отдаёт этой странице всё, что не заняли полосы над ней (см.
     `shell--fills`). Своей высоты она не просит — потому и не переполняет. */
  min-height: 0;
}

/*
 * Поля всех полос переписки — одной величиной.
 *
 * Шапка, состав, лента и поле ввода лежат друг под другом, и стоило им разойтись
 * на десятую рема, как имя собеседника, край пузыря и край поля ввода перестали
 * попадать на одну вертикаль.
 */
.pane {
  --pane-pad: 0.9rem;

  display: flex;
  min-height: 0;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
}

/* Держатель ленты: по нему позиционируется кнопка «вниз». */
.pane__thread {
  position: relative;
  display: flex;
  min-height: 0;
  flex: 1;
  flex-direction: column;
}

.down {
  position: absolute;
  right: 1rem;
  bottom: 1rem;
  display: grid;
  place-items: center;
  width: 2.6rem;
  height: 2.6rem;
  padding: 0;
  border: 1px solid var(--color-border);
  border-radius: 50%;
  background: var(--color-surface-raised);
  color: var(--color-text-muted);
  box-shadow: var(--shadow-md);
  cursor: pointer;
}

.down:hover {
  color: var(--color-text);
}

.down svg {
  width: 1.3rem;
  height: 1.3rem;
}

.down__count {
  position: absolute;
  top: -0.3rem;
  right: -0.3rem;
  min-width: 1.2rem;
  padding: 0 0.3rem;
  border-radius: var(--radius-pill);
  background: var(--color-accent);
  color: var(--color-accent-text);
  font-size: 0.7rem;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  line-height: 1.2rem;
  text-align: center;
}

.pop-enter-active,
.pop-leave-active {
  transition: opacity 0.16s ease, transform 0.2s cubic-bezier(0.22, 1, 0.36, 1);
}

.pop-enter-from,
.pop-leave-to {
  opacity: 0;
  transform: translateY(0.5rem) scale(0.9);
}

/* На узком экране панель одна: список, пока никто не выбран, и лента, когда
   выбран. Обе сразу туда не помещаются, а показывать половину каждой хуже, чем
   показывать одну целиком. */
@media (max-width: 47.9rem) {
  .messenger {
    grid-template-columns: minmax(0, 1fr);
    /*
     * По бокам — до самых кромок.
     *
     * Поля ставит оболочка, одни на все страницы, и мессенджеру они не идут:
     * карточка с отступом по краям уместна там, где страница листается, а здесь
     * она сама себе экран. Гасим их отрицательным полем, а не правкой оболочки:
     * те же поля нужны всем остальным страницам, включая соседние с тем же
     * `fills`.
     *
     * Снизу поля нет — его уже сняла оболочка, когда убрала полосу разделов, —
     * и отрицательное там вылезло бы за нижнюю кромку.
     */
    margin: 0 -1rem;
  }

  /*
   * Сверху до кромки уходит только открытый разговор.
   *
   * У него наверху своя шапка с именем собеседника, и она встаёт на место
   * системной — так и должно быть. А список остаётся списком: заголовок
   * «Сообщения», прижатый к самому краю экрана, выглядит обрезанным.
   */
  .messenger--open {
    margin-top: -1rem;
  }

  /* Рамки и скругления у того, что занимает весь экран, обрамлять нечего. */
  .pane {
    border: none;
    border-radius: 0;
    display: none;
  }

  .messenger--open .messenger__list {
    display: none;
  }

  .messenger--open .pane {
    display: flex;
  }
}

@media (prefers-reduced-motion: reduce) {
  .pop-enter-active,
  .pop-leave-active {
    transition: none;
  }
}
</style>
