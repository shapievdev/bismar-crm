<script setup lang="ts">
import type { ChatMessage, ChatPerson } from '~/types/chat'

// `fills`: страница занимает ровно экран и не растёт с содержимым — оболочка
// объявляет себя в `100dvh` и снимает нижний отступ. См. «Высота» ниже.
definePageMeta({ middleware: 'auth', fills: true })
useHead({ title: 'Сообщения' })

const { user } = useAuth()
const api = useChatApi()
const messenger = useMessenger()
const {
  conversations,
  messages,
  typing,
  hasMore,
  activeId,
  active,
} = messenger

const route = useRoute()
const router = useRouter()

/*
 * Переписка выбирается адресом: ?id=12.
 *
 * Так на неё можно сослаться — из карточки ответственного за курс, из ответа
 * консультанта, — и так работает кнопка «назад» в браузере, которой на двух
 * панелях пользуются постоянно.
 */
onMounted(async () => {
  await messenger.connect()

  // ?write=7 — «написать вот этому человеку»: так сюда ведут карточки
  // ответственных за курс и совет консультанта. Переписка заводится или
  // находится прежняя, и адрес тут же подменяется на её номер.
  const addressee = Number(route.query.write)

  if (addressee) {
    const id = await messenger.writeTo(addressee)

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
    // и без этого кнопка «назад» меняла адрес, не закрывая ленту: нажатие
    // выглядело как не сработавшее.
    messenger.closeThread()

    return
  }

  if (wanted !== activeId.value) {
    await openConversation(wanted)
  }
})

onBeforeUnmount(() => messenger.closeThread())

const isOpening = ref(false)

async function openConversation(id: number): Promise<void> {
  isOpening.value = true

  try {
    await messenger.open(id)
    await scrollToEnd()
  }
  finally {
    isOpening.value = false
  }
}

function select(id: number): void {
  void router.push({ query: { id } })
}

/* ---------- Отправка ---------- */

const draft = ref('')
const files = ref<File[]>([])
const isSending = ref(false)
const filePicker = useTemplateRef<HTMLInputElement>('filePicker')

/* ---------- Ответ и правка ---------- */

/**
 * На что отвечаем и что переписываем. Одновременно ни то ни другое: правка
 * своей реплики и ответ на чужую — разные намерения, и одно поле ввода не может
 * означать оба сразу. Начатое второе отменяет первое.
 */
const replyTo = ref<ChatMessage | null>(null)
const editing = ref<ChatMessage | null>(null)

/** У какого сообщения открыто меню действий. */
const menuFor = ref<number | null>(null)

/**
 * Куда раскрывать меню — вниз или вверх.
 *
 * Лента прокручивается, а меню лежит внутри неё, и её нижний край его срезает:
 * у последних сообщений — а именно с ними чаще всего что-то делают — нижние
 * пункты оказывались за краем и не нажимались вовсе. Поэтому у реплики из
 * нижней половины меню раскрывается вверх, и целиком остаётся внутри.
 */
const menuUp = ref(false)

function toggleMenu(message: ChatMessage, event: MouseEvent): void {
  if (menuFor.value === message.id) {
    menuFor.value = null

    return
  }

  const bubble = (event.currentTarget as HTMLElement).closest('.bubble')
  const box = thread.value?.getBoundingClientRect()

  if (bubble && box) {
    const top = bubble.getBoundingClientRect().top
    menuUp.value = top > box.top + box.height / 2
  }

  menuFor.value = message.id
}

// Клик мимо закрывает меню — как всякое всплывающее. Нажатие на саму кнопку
// до документа не доходит (`@click.stop`), иначе меню закрывалось бы тем же
// щелчком, которым открылось.
function closeMenu(): void {
  menuFor.value = null
}

onMounted(() => document.addEventListener('click', closeMenu))
onBeforeUnmount(() => document.removeEventListener('click', closeMenu))

/** Править можно только своё и только сказанное словами. */
function canEdit(message: ChatMessage): boolean {
  return message.kind === 'text' && isMine(message)
}

/** Удалять — своё, а в группе ещё и чужое, если группу завёл ты. */
function canDelete(message: ChatMessage): boolean {
  return message.kind === 'text' && (isMine(message) || active.value?.is_owner === true)
}

function startReply(message: ChatMessage): void {
  editing.value = null
  replyTo.value = message
  menuFor.value = null
  field.value?.focus()
}

function startEditing(message: ChatMessage): void {
  replyTo.value = null
  editing.value = message
  // Высоту поля подгонит наблюдатель за draft — здесь только фокус.
  draft.value = message.body ?? ''
  menuFor.value = null

  void nextTick(() => field.value?.focus())
}

/** Отмена возвращает поле к тому, что в нём было до правки, — то есть к пустому. */
function cancelComposing(): void {
  if (editing.value) {
    draft.value = ''
  }

  replyTo.value = null
  editing.value = null
}

async function removeMessage(message: ChatMessage): Promise<void> {
  menuFor.value = null

  await messenger.remove(message.id)

  // Правили или отвечали именно на неё — теперь не на что.
  if (editing.value?.id === message.id) {
    cancelComposing()
  }

  if (replyTo.value?.id === message.id) {
    replyTo.value = null
  }
}

const canSend = computed(() => {
  if (isSending.value) {
    return false
  }

  // При правке пустой текст — это удаление, а его делают иначе: у пустого поля
  // кнопка просто неактивна.
  return editing.value
    ? draft.value.trim().length > 0
    : draft.value.trim().length > 0 || files.value.length > 0
})

async function submit(): Promise<void> {
  if (!canSend.value) {
    return
  }

  isSending.value = true

  try {
    if (editing.value) {
      await messenger.edit(editing.value.id, draft.value.trim())
      editing.value = null
      draft.value = ''
    }
    else {
      await messenger.send(draft.value.trim(), files.value, replyTo.value?.id ?? null)
      replyTo.value = null
      draft.value = ''
      files.value = []

      if (filePicker.value) {
        filePicker.value.value = ''
      }

      await scrollToEnd()
    }
  }
  finally {
    isSending.value = false
  }
}

/**
 * Поле растёт под сообщение.
 *
 * На телефоне это важнее, чем на столе: строка в одну высоту прячет от
 * пишущего всё, кроме последних слов, а ручку растягивания там не ухватить.
 */
const field = useTemplateRef<HTMLTextAreaElement>('field')

function fitField(): void {
  const element = field.value

  if (element) {
    element.style.height = 'auto'
    element.style.height = `${Math.min(element.scrollHeight, 128)}px`
  }
}

watch(draft, () => nextTick(fitField))

function pickFiles(event: Event): void {
  const chosen = (event.target as HTMLInputElement).files

  files.value = chosen ? [...chosen] : []
}

function dropFile(index: number): void {
  files.value = files.value.filter((_, at) => at !== index)
}

/**
 * Enter отправляет, Shift+Enter переносит строку — как во всяком чате.
 */
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    void submit()

    return
  }

  // Escape бросает начатую правку или ответ — там же, где их и начали.
  if (event.key === 'Escape' && (editing.value || replyTo.value)) {
    event.preventDefault()
    cancelComposing()

    return
  }

  announce()
}

/**
 * Оповещение о наборе — не чаще раза в две секунды.
 *
 * Каждое нажатие клавиши уходило бы в сокет отдельным пакетом, а собеседнику
 * от этого ни теплее ни холоднее: надпись «печатает» и так висит три секунды.
 */
let lastAnnounced = 0

function announce(): void {
  const now = Date.now()

  if (now - lastAnnounced > 2000) {
    lastAnnounced = now
    messenger.announceTyping()
  }
}

/* ---------- Высота ---------- */

/*
 * Её здесь больше нет, и это осознанно.
 *
 * Раньше высоту считал скрипт: мерил от своей верхней кромки до низа видимой
 * области и переписывал её на каждое событие. На телефоне это оборачивалось
 * гонкой с браузером — при прокрутке уезжает адресная строка, события идут
 * потоком, высота переписывается на каждом кадре, лента дёргается, а внизу
 * остаётся пустота.
 *
 * Теперь всё делает раскладка: оболочка страницы объявлена в `100dvh` и
 * растягивает эту строку сетки до низа (`shell--fills` в layouts/default.vue),
 * а мессенджер занимает её целиком. Клавиатуру берёт на себя
 * `interactive-widget=resizes-content` из viewport (nuxt.config.ts): с ним она
 * сжимает саму разметку, и `dvh` учитывает её наравне с адресной строкой.
 */

/* ---------- Прокрутка ленты ---------- */

const thread = useTemplateRef<HTMLElement>('thread')

async function scrollToEnd(): Promise<void> {
  await nextTick()

  if (thread.value) {
    thread.value.scrollTop = thread.value.scrollHeight
  }
}

/**
 * Перескок к процитированной реплике.
 *
 * Только если она уже загружена: догружать ради этого всё, что было между,
 * можно очень долго — цитата могла быть годичной давности. Не нашли — ничего не
 * делаем, цитата и так сказала главное.
 */
const highlighted = ref<number | null>(null)

function jumpTo(messageId: number): void {
  const target = thread.value?.querySelector(`[data-message="${messageId}"]`)

  if (!target) {
    return
  }

  target.scrollIntoView({ behavior: 'smooth', block: 'center' })

  // Подсветка гаснет сама: она отвечает на «куда меня перенесло», а дальше
  // только мешает читать.
  highlighted.value = messageId
  setTimeout(() => {
    if (highlighted.value === messageId) {
      highlighted.value = null
    }
  }, 1600)
}

/** Дочитали до верха — подгружаем то, что было раньше, сохраняя место. */
async function onThreadScroll(): Promise<void> {
  const element = thread.value

  if (!element || element.scrollTop > 60 || !hasMore.value || isOpening.value) {
    return
  }

  const before = element.scrollHeight

  await messenger.loadOlder()
  await nextTick()

  element.scrollTop = element.scrollHeight - before
}

// Новое сообщение в открытой ленте — прокручиваем, если человек и так внизу.
watch(() => messages.value.length, async () => {
  const element = thread.value

  if (!element) {
    return
  }

  const atBottom = element.scrollHeight - element.scrollTop - element.clientHeight < 150

  if (atBottom) {
    await scrollToEnd()
  }
})

/* ---------- Новая переписка ---------- */

const isComposing = ref(false)
const contactSearch = ref('')
const contacts = ref<ChatPerson[]>([])
const groupTitle = ref('')
const groupMembers = ref<ChatPerson[]>([])

let searchTimer: ReturnType<typeof setTimeout> | undefined

watch(contactSearch, (value) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(async () => {
    contacts.value = (await api.searchContacts(value.trim())).data
  }, 250)
})

async function startComposing(): Promise<void> {
  isComposing.value = true
  groupTitle.value = ''
  groupMembers.value = []
  contactSearch.value = ''
  contacts.value = (await api.searchContacts()).data
}

/** Один человек — личная переписка, несколько — группа. */
function toggleMember(person: ChatPerson): void {
  groupMembers.value = groupMembers.value.some(one => one.id === person.id)
    ? groupMembers.value.filter(one => one.id !== person.id)
    : [...groupMembers.value, person]
}

function isChosen(person: ChatPerson): boolean {
  return groupMembers.value.some(one => one.id === person.id)
}

const canStart = computed(() =>
  groupMembers.value.length === 1
  || (groupMembers.value.length > 1 && groupTitle.value.trim().length > 0),
)

async function startConversation(): Promise<void> {
  if (!canStart.value) {
    return
  }

  const single = groupMembers.value.length === 1 ? groupMembers.value[0] : null

  const id = single
    ? await messenger.writeTo(single.id)
    : (await api.startGroup(groupTitle.value.trim(), groupMembers.value.map(one => one.id))).data.id

  await messenger.refreshConversations()

  isComposing.value = false
  select(id)
}

/* ---------- Группа ---------- */

const isManaging = ref(false)
const inviteSearch = ref('')
const invitees = ref<ChatPerson[]>([])
const newTitle = ref('')

let inviteTimer: ReturnType<typeof setTimeout> | undefined

watch(inviteSearch, (value) => {
  clearTimeout(inviteTimer)
  inviteTimer = setTimeout(async () => {
    invitees.value = (await api.searchContacts(value.trim())).data
  }, 250)
})

function startManaging(): void {
  isManaging.value = true
  newTitle.value = active.value?.title ?? ''
  inviteSearch.value = ''
  invitees.value = []
}

async function invite(person: ChatPerson): Promise<void> {
  if (!activeId.value) {
    return
  }

  await api.addParticipants(activeId.value, [person.id])
  await refreshActive()
  inviteSearch.value = ''
  invitees.value = []
}

async function expel(person: ChatPerson): Promise<void> {
  if (!activeId.value) {
    return
  }

  await api.removeParticipant(activeId.value, person.id)
  await refreshActive()
}

async function rename(): Promise<void> {
  if (!activeId.value || newTitle.value.trim() === '' || newTitle.value.trim() === active.value?.title) {
    return
  }

  await api.renameConversation(activeId.value, newTitle.value.trim())
  await messenger.refreshConversations()
}

async function leave(): Promise<void> {
  if (!activeId.value || !window.confirm('Выйти из группы? Переписка останется у остальных.')) {
    return
  }

  await api.leaveConversation(activeId.value)
  isManaging.value = false
  messenger.closeThread()
  await messenger.refreshConversations()
  void router.push({ query: {} })
}

async function refreshActive(): Promise<void> {
  await messenger.refreshConversations()
}

/* ---------- Показ ---------- */

/** Собственные сообщения выравниваются по правому краю, как везде в чатах. */
function isMine(message: ChatMessage): boolean {
  return message.author?.id === user.value?.id
}

/** Прочитано ли сообщение собеседником — вторая галочка. */
function isSeen(message: ChatMessage): boolean {
  const others = (active.value?.participants ?? []).filter(one => one.id !== user.value?.id)

  return others.length > 0 && others.every(one =>
    one.last_read_at !== null
    && one.last_read_at !== undefined
    && message.created_at !== null
    && new Date(one.last_read_at) >= new Date(message.created_at),
  )
}

function time(iso: string | null): string {
  return iso ? new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : ''
}

/** Подпись в списке: кто и что сказал последним. */
function preview(message: ChatMessage | undefined): string {
  if (!message) {
    return 'Пока ничего не сказано'
  }

  if (message.body) {
    return message.body
  }

  return message.attachments.length ? 'Файл' : ''
}

function day(iso: string | null): string {
  if (!iso) {
    return ''
  }

  const date = new Date(iso)
  const today = new Date()

  return date.toDateString() === today.toDateString()
    ? 'Сегодня'
    : date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })
}

/** Отбивка с датой ставится там, где день сменился. */
function startsNewDay(message: ChatMessage, index: number): boolean {
  const previous = messages.value[index - 1]

  return !previous || day(previous.created_at) !== day(message.created_at)
}

function sizeOf(bytes: number): string {
  return bytes < 1024 * 1024
    ? `${Math.max(1, Math.round(bytes / 1024))} КБ`
    : `${(bytes / 1024 / 1024).toFixed(1)} МБ`
}

const typingLabel = computed(() => {
  if (typing.value.length === 0) {
    return ''
  }

  return typing.value.length === 1
    ? `${typing.value[0]?.name} печатает…`
    : 'Печатают…'
})
</script>

<template>
  <section class="messenger" :class="{ 'messenger--open': activeId !== null }">
    <!-- Слева переписки, справа лента. На узком экране показывается одна из
         двух: список, пока никто не выбран, и лента, когда выбран. -->
    <aside class="list">
      <header class="list__head">
        <h1 class="page-title list__title">
          Сообщения
        </h1>
        <button type="button" class="button-primary button-sm" @click="startComposing">
          Написать
        </button>
      </header>

      <p v-if="!conversations.length" class="muted list__empty">
        Переписок пока нет. Напишите коллеге — например тому, кто отвечает за курс.
      </p>

      <button
        v-for="conversation in conversations"
        :key="conversation.id"
        type="button"
        class="row"
        :class="{ 'row--active': conversation.id === activeId }"
        @click="select(conversation.id)"
      >
        <span class="row__face">
          <UserAvatar
            :name="conversation.title"
            :src="conversation.companion?.avatar_url ?? null"
            :size="40"
          />
          <!-- Зелёная точка у собеседника: presence-канал знает, кто сейчас
               подключён, и это не стоит ни запроса, ни строки в базе. -->
          <span
            v-if="!conversation.is_group && messenger.isOnline(conversation.companion?.id)"
            class="row__online"
            title="В сети"
          />
        </span>

        <span class="row__body">
          <span class="row__top">
            <span class="row__name">{{ conversation.title }}</span>
            <span class="row__time faint">{{ time(conversation.last_message_at) }}</span>
          </span>
          <span class="row__preview faint">{{ preview(conversation.last_message) }}</span>
        </span>

        <span v-if="conversation.unread_count" class="row__unread">{{ conversation.unread_count }}</span>
      </button>
    </aside>

    <!-- Лента -->
    <div class="pane">
      <template v-if="active">
        <header class="pane__head">
          <button type="button" class="pane__back" aria-label="К списку" @click="router.push({ query: {} })">
            ←
          </button>

          <UserAvatar :name="active.title" :src="active.companion?.avatar_url ?? null" :size="36" />

          <div class="pane__who">
            <span class="pane__name">{{ active.title }}</span>
            <span class="faint pane__status">
              <template v-if="typingLabel">{{ typingLabel }}</template>
              <template v-else-if="active.is_group">{{ active.participants_count }} участника(ов)</template>
              <template v-else-if="messenger.isOnline(active.companion?.id)">В сети</template>
              <template v-else>Не в сети</template>
            </span>
          </div>

          <button
            v-if="active.is_group"
            type="button"
            class="button-ghost button-sm"
            @click="isManaging ? isManaging = false : startManaging()"
          >
            {{ isManaging ? 'Готово' : 'Участники' }}
          </button>
        </header>

        <!-- Состав группы: правит владелец, выйти может любой. -->
        <div v-if="isManaging && active.is_group" class="crew">
          <div v-if="active.is_owner" class="crew__rename">
            <input v-model="newTitle" class="input" maxlength="120" placeholder="Название группы">
            <button type="button" class="button-secondary button-sm" @click="rename">
              Переименовать
            </button>
          </div>

          <ul class="crew__list">
            <li v-for="person in active.participants" :key="person.id" class="crew__item">
              <UserAvatar :name="person.name" :src="person.avatar_url" :size="28" />
              <span class="crew__name">{{ person.name }}</span>
              <button
                v-if="active.is_owner && person.id !== user?.id"
                type="button"
                class="crew__remove"
                @click="expel(person)"
              >
                Убрать
              </button>
            </li>
          </ul>

          <div v-if="active.is_owner" class="crew__invite">
            <input v-model="inviteSearch" type="search" class="input" placeholder="Добавить: фамилия или почта">
            <ul v-if="invitees.length" class="finder">
              <li v-for="person in invitees" :key="person.id">
                <button type="button" class="finder__option" @click="invite(person)">
                  <UserAvatar :name="person.name" :src="person.avatar_url" :size="26" />
                  <span>{{ person.name }}</span>
                </button>
              </li>
            </ul>
          </div>

          <button type="button" class="button-ghost button-sm crew__leave" @click="leave">
            Выйти из группы
          </button>
        </div>

        <div ref="thread" class="thread" @scroll="onThreadScroll">
          <p v-if="hasMore" class="thread__older faint">
            Прокрутите вверх, чтобы догрузить прошлое
          </p>

          <template v-for="(message, index) in messages" :key="message.id">
            <p v-if="startsNewDay(message, index)" class="thread__day">
              {{ day(message.created_at) }}
            </p>

            <!-- Системная отметка: кто кого добавил, кто вышел. -->
            <p v-if="message.kind === 'system'" class="system">
              {{ message.body }}
            </p>

            <div
              v-else
              class="bubble"
              :class="{ 'bubble--mine': isMine(message), 'bubble--found': highlighted === message.id }"
              :data-message="message.id"
            >
              <span v-if="active.is_group && !isMine(message)" class="bubble__author">
                {{ message.author?.name ?? 'Бывший сотрудник' }}
              </span>

              <!-- Цитата: на что отвечали. Удалённая говорит об этом прямо,
                   иначе ответ висел бы без того, с чем соглашались. -->
              <button
                v-if="message.reply_to"
                type="button"
                class="quote"
                :class="{ 'quote--gone': message.reply_to.deleted }"
                @click="jumpTo(message.reply_to.id)"
              >
                <span class="quote__author">
                  {{ message.reply_to.author?.name ?? 'Бывший сотрудник' }}
                </span>
                <span class="quote__text">
                  {{ message.reply_to.deleted ? 'Сообщение удалено' : message.reply_to.excerpt }}
                </span>
              </button>

              <p v-if="message.body" class="bubble__text">
                {{ message.body }}
              </p>

              <a
                v-for="file in message.attachments"
                :key="file.id"
                :href="file.url ?? '#'"
                target="_blank"
                rel="noopener noreferrer"
                class="file"
              >
                <img v-if="file.opens_inline && file.mime_type?.startsWith('image/')" :src="file.url ?? ''" :alt="file.name" class="file__image">
                <template v-else>
                  <span class="file__name">{{ file.name }}</span>
                  <span class="faint file__size">{{ sizeOf(file.size) }}</span>
                </template>
              </a>

              <span class="bubble__meta">
                <!-- «изменено» стоит раньше времени: время относится к тому,
                     когда сказали, а правка — к тому, что теперь написано. -->
                <span v-if="message.edited_at" :title="`Изменено ${time(message.edited_at)}`">изменено</span>
                {{ time(message.created_at) }}
                <!-- Вторая галочка — когда собеседник дочитал до этого места. -->
                <template v-if="isMine(message)">{{ isSeen(message) ? '✓✓' : '✓' }}</template>
              </span>

              <!-- Действия над репликой. Кнопкой, а не долгим нажатием: долгое
                   нажатие на телефоне уже занято выделением текста, и отнимать
                   его у того, кто хочет скопировать сообщение, нельзя. -->
              <button
                type="button"
                class="bubble__more"
                :aria-label="`Действия с сообщением от ${time(message.created_at)}`"
                @click.stop="toggleMenu(message, $event)"
              >
                ⋯
              </button>

              <ul v-if="menuFor === message.id" class="actions" :class="{ 'actions--up': menuUp }">
                <li>
                  <button type="button" @click="startReply(message)">
                    Ответить
                  </button>
                </li>
                <li v-if="canEdit(message)">
                  <button type="button" @click="startEditing(message)">
                    Изменить
                  </button>
                </li>
                <li v-if="canDelete(message)">
                  <button type="button" class="actions__danger" @click="removeMessage(message)">
                    Удалить у всех
                  </button>
                </li>
              </ul>
            </div>
          </template>
        </div>

        <form class="composer" @submit.prevent="submit">
          <!-- Что сейчас делается с полем: отвечаем или переписываем. Без этой
               полосы правка неотличима от нового сообщения, и человек
               отправляет второе вместо исправления первого. -->
          <div v-if="replyTo || editing" class="composing">
            <span class="composing__kind">{{ editing ? 'Изменение' : 'Ответ' }}</span>
            <span class="composing__text">
              {{ editing ? (editing.body ?? '') : (replyTo?.body ?? 'Вложение') }}
            </span>
            <button type="button" class="composing__cancel" aria-label="Отменить" @click="cancelComposing">
              ✕
            </button>
          </div>

          <ul v-if="files.length" class="composer__files">
            <li v-for="(file, index) in files" :key="`${file.name}-${index}`">
              {{ file.name }}
              <button type="button" @click="dropFile(index)">
                ✕
              </button>
            </li>
          </ul>

          <div class="composer__row">
            <!-- При правке скрепка убрана: правка меняет слова, а приложить
                 файл задним числом — это новое сообщение. -->
            <label v-if="!editing" class="composer__clip" title="Приложить файл">
              📎
              <input ref="filePicker" type="file" multiple hidden @change="pickFiles">
            </label>

            <textarea
              ref="field"
              v-model="draft"
              class="input composer__field"
              rows="1"
              maxlength="5000"
              :placeholder="editing ? 'Изменить сообщение…' : 'Сообщение…'"
              @keydown="onKeydown"
            />

            <!-- На телефоне подпись сворачивается в стрелку: со словом
                 «Отправить» кнопка забирала треть строки, и поле ввода
                 оставалось уже самой кнопки. -->
            <button
              type="submit"
              class="button-primary composer__send"
              :disabled="!canSend"
              :aria-label="editing ? 'Сохранить' : 'Отправить'"
            >
              <span class="composer__send-word">
                {{ isSending ? '…' : (editing ? 'Сохранить' : 'Отправить') }}
              </span>
              <span class="composer__send-sign" aria-hidden="true">
                {{ isSending ? '…' : (editing ? '✓' : '↑') }}
              </span>
            </button>
          </div>
        </form>
      </template>

      <UiEmptyState
        v-else
        title="Выберите переписку"
        description="Слева — те, с кем вы уже говорили. Кнопка «Написать» заведёт новую."
      />
    </div>

    <!-- Новая переписка: один выбранный — личная, несколько — группа. -->
    <div v-if="isComposing" class="sheet" @click.self="isComposing = false">
      <div class="sheet__panel card">
        <header class="sheet__head">
          <h2 class="sheet__title">
            Новая переписка
          </h2>
          <button type="button" class="button-ghost button-sm" @click="isComposing = false">
            Закрыть
          </button>
        </header>

        <input v-model="contactSearch" type="search" class="input" placeholder="Кому: фамилия или почта">

        <p v-if="groupMembers.length > 1" class="muted sheet__hint">
          Выбрано больше одного — получится группа, ей нужно название.
        </p>

        <input
          v-if="groupMembers.length > 1"
          v-model="groupTitle"
          class="input"
          maxlength="120"
          placeholder="Название группы"
        >

        <ul class="sheet__people">
          <li v-for="person in contacts" :key="person.id">
            <button
              type="button"
              class="finder__option"
              :class="{ 'finder__option--chosen': isChosen(person) }"
              @click="toggleMember(person)"
            >
              <UserAvatar :name="person.name" :src="person.avatar_url" :size="30" />
              <span class="sheet__person">
                <span>{{ person.name }}</span>
                <span class="faint">{{ person.email }}</span>
              </span>
              <span v-if="isChosen(person)" class="sheet__tick">✓</span>
            </button>
          </li>
        </ul>

        <button type="button" class="button-primary" :disabled="!canStart" @click="startConversation">
          {{ groupMembers.length > 1 ? 'Создать группу' : 'Написать' }}
        </button>
      </div>
    </div>
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
  grid-template-columns: 20rem 1fr;
  gap: 1rem;
  /* Ровно то, что осталось от экрана: оболочка страницы объявлена в высоту
     экрана и растягивает эту строку сетки до низа (см. `shell--fills`). */
  height: 100%;
  min-height: 0;
}

.list {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  overflow-y: auto;
  padding-right: 0.25rem;
}

.list__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.35rem;
}

.list__title {
  margin: 0;
  font-size: 1.35rem;
}

.list__empty {
  font-size: 0.88rem;
  line-height: 1.5;
}

.row {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  width: 100%;
  padding: 0.55rem 0.6rem;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.row:hover {
  background: var(--control-surface-hover);
}

.row--active {
  background: var(--color-surface-sunken);
}

.row__face {
  position: relative;
  flex-shrink: 0;
}

.row__online {
  position: absolute;
  right: -1px;
  bottom: -1px;
  width: 0.7rem;
  height: 0.7rem;
  border: 2px solid var(--color-surface);
  border-radius: 50%;
  background: var(--color-success, #3fb950);
}

.row__body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.row__top {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
}

.row__name {
  overflow: hidden;
  font-size: 0.92rem;
  font-weight: 550;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.row__time {
  flex-shrink: 0;
  font-size: 0.75rem;
}

.row__preview {
  overflow: hidden;
  font-size: 0.82rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.row__unread {
  flex-shrink: 0;
  min-width: 1.3rem;
  padding: 0 0.35rem;
  border-radius: var(--radius-pill);
  background: var(--color-accent);
  color: var(--color-accent-text);
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1.3rem;
  text-align: center;
}

.pane {
  display: flex;
  flex-direction: column;
  min-height: 0;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  overflow: hidden;
}

.pane__head {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.7rem 0.9rem;
  border-bottom: 1px solid var(--color-border);
}

.pane__back {
  display: none;
  border: none;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 1.2rem;
  cursor: pointer;
}

.pane__who {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.pane__name {
  overflow: hidden;
  font-weight: 550;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pane__status {
  font-size: 0.78rem;
}

.thread {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  flex: 1;
  overflow-y: auto;
  padding: 1rem;
}

.thread__older,
.thread__day {
  margin: 0.4rem 0;
  font-size: 0.75rem;
  text-align: center;
}

.thread__day {
  color: var(--color-text-faint);
}

.system {
  margin: 0.3rem auto;
  padding: 0.25rem 0.7rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  font-size: 0.78rem;
  text-align: center;
}

.bubble {
  position: relative;
  display: flex;
  flex-direction: column;
  align-self: flex-start;
  gap: 0.15rem;
  max-width: min(34rem, 78%);
  padding: 0.5rem 0.75rem;
  border-radius: var(--radius-lg);
  background: var(--color-surface-sunken);
}

/* Куда перенесло по нажатию на цитату. Гаснет само — см. jumpTo(). */
.bubble--found {
  outline: 2px solid var(--color-highlight-strong);
  outline-offset: 2px;
}

.bubble--mine {
  align-self: flex-end;
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.bubble__author {
  font-size: 0.75rem;
  font-weight: 600;
  opacity: 0.75;
}

.bubble__text {
  margin: 0;
  font-size: 0.92rem;
  line-height: 1.45;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.bubble__meta {
  display: flex;
  gap: 0.35rem;
  align-self: flex-end;
  font-size: 0.7rem;
  opacity: 0.7;
}

/* ---------- Цитата над ответом ---------- */

.quote {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  width: 100%;
  padding: 0.3rem 0.5rem;
  border: 0;
  /* Полоса слева — то, чем цитата отличается от текста самого сообщения. */
  border-left: 2px solid currentcolor;
  border-radius: var(--radius-sm);
  background: color-mix(in srgb, currentcolor 12%, transparent);
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.quote:hover {
  background: color-mix(in srgb, currentcolor 18%, transparent);
}

/* Удалённую не к чему перематывать: курсор об этом и говорит. */
.quote--gone {
  cursor: default;
  font-style: italic;
}

.quote__author {
  font-size: 0.72rem;
  font-weight: 600;
}

.quote__text {
  overflow: hidden;
  font-size: 0.78rem;
  text-overflow: ellipsis;
  white-space: nowrap;
  opacity: 0.85;
}

/* ---------- Действия над репликой ---------- */

.bubble__more {
  position: absolute;
  top: 0.15rem;
  right: 0.3rem;
  padding: 0 0.2rem;
  border: 0;
  background: none;
  color: inherit;
  font-size: 0.9rem;
  line-height: 1;
  cursor: pointer;
  opacity: 0;
  transition: opacity 0.15s ease;
}

.bubble:hover .bubble__more,
.bubble__more:focus-visible {
  opacity: 0.6;
}

/*
 * Под пальцем наведения не бывает, и спрятанная кнопка недосягаема — там она
 * видна всегда. Узкое окно на столе сюда же: мышь там есть, но раскладка уже
 * телефонная, и прятать единственный путь к действиям незачем.
 */
@media (pointer: coarse), (max-width: 52rem) {
  .bubble__more {
    opacity: 0.5;
  }
}

.actions {
  position: absolute;
  top: 1.5rem;
  right: 0.3rem;
  z-index: 5;
  min-width: 11rem;
  margin: 0;
  padding: 0.25rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface-raised);
  color: var(--color-text);
  box-shadow: 0 12px 28px rgb(0 0 0 / 18%);
  list-style: none;
}

/* Раскрытое вверх — для реплик у нижнего края ленты, см. toggleMenu(). Идёт
   после `.actions`: специфичность одинаковая, и решает порядок. */
.actions--up {
  top: auto;
  bottom: 1.5rem;
}

.actions button {
  width: 100%;
  padding: 0.45rem 0.6rem;
  border: 0;
  border-radius: var(--radius-sm);
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.actions button:hover {
  background: var(--color-surface-sunken);
}

.actions__danger {
  color: var(--color-danger);
}

/* ---------- Полоса «отвечаем / изменяем» ---------- */

.composing {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  padding: 0.4rem 0.6rem;
  border-left: 2px solid var(--color-accent);
  border-radius: var(--radius-sm);
  background: var(--color-surface-sunken);
  font-size: 0.82rem;
}

.composing__kind {
  flex-shrink: 0;
  font-weight: 600;
}

.composing__text {
  overflow: hidden;
  flex: 1;
  text-overflow: ellipsis;
  white-space: nowrap;
  opacity: 0.75;
}

.composing__cancel {
  flex-shrink: 0;
  padding: 0 0.25rem;
  border: 0;
  background: none;
  color: inherit;
  cursor: pointer;
}

/* На широком экране у кнопки слово, знак спрятан. Подмена — в медиазапросе. */
.composer__send {
  flex-shrink: 0;
}

.composer__send-sign {
  display: none;
}

.file {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-top: 0.2rem;
  color: inherit;
  font-size: 0.85rem;
}

.file__image {
  max-width: 100%;
  border-radius: var(--radius);
}

.composer {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.7rem 0.9rem;
  border-top: 1px solid var(--color-border);
}

.composer__files {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 0.8rem;
}

.composer__files li {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.2rem 0.5rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
}

.composer__files button {
  border: none;
  background: transparent;
  color: inherit;
  cursor: pointer;
}

.composer__row {
  display: flex;
  align-items: flex-end;
  gap: 0.5rem;
}

/* Отдельная величина под палец: на телефоне значок в один символ не поймать. */
.composer__clip {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: var(--radius);
  cursor: pointer;
  font-size: 1.1rem;
}

.composer__clip:hover {
  background: var(--control-surface-hover);
}

.composer__field {
  flex: 1;
  max-height: 8rem;
  resize: none;
}

.crew {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  padding: 0.85rem 0.9rem;
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-sunken);
}

.crew__rename,
.crew__invite {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.crew__list {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.crew__item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.88rem;
}

.crew__name {
  flex: 1;
}

.crew__remove,
.crew__leave {
  border: none;
  background: transparent;
  color: var(--color-danger);
  font: inherit;
  font-size: 0.82rem;
  cursor: pointer;
}

.crew__leave {
  align-self: flex-start;
}

.finder {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.finder__option {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  width: 100%;
  padding: 0.35rem 0.5rem;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.finder__option:hover,
.finder__option--chosen {
  background: var(--control-surface-hover);
}

/* Лист поверх страницы — единственное место, где он тут уместен: выбор
   собеседника перекрывает и список, и ленту. */
.sheet {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: color-mix(in srgb, var(--color-text) 35%, transparent);
}

.sheet__panel {
  max-height: min(36rem, 85dvh);
}

.sheet__panel {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  width: min(28rem, 100%);
  padding: 1.1rem 1.2rem;
  overflow-y: auto;
}

.sheet__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.sheet__title {
  margin: 0;
  font-size: 1.05rem;
}

.sheet__hint {
  margin: 0;
  font-size: 0.82rem;
}

.sheet__people {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  flex: 1;
  margin: 0;
  padding: 0;
  list-style: none;
  overflow-y: auto;
}

.sheet__person {
  display: flex;
  flex-direction: column;
  flex: 1;
  font-size: 0.88rem;
}

.sheet__tick {
  color: var(--color-accent);
}

/* На узком экране панели не помещаются рядом: показываем ту, что нужна. */
@media (max-width: 52rem) {
  .messenger {
    grid-template-columns: 1fr;
  }

  .messenger--open .list {
    display: none;
  }

  .messenger:not(.messenger--open) .pane {
    display: none;
  }

  /* Возврат к списку — единственный способ уйти из переписки, когда панель
     одна: на столе для этого достаточно посмотреть влево. */
  .pane__back {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 2.25rem;
    height: 2.25rem;
    margin-left: -0.3rem;
    border-radius: var(--radius);
  }

  .pane__back:hover {
    background: var(--control-surface-hover);
  }

  .thread {
    padding: 0.75rem;
  }

  /* Пузырь на телефоне шире: 78% от 390 точек — это обрывок строки. */
  .bubble {
    max-width: 88%;
  }

  /*
   * Кнопка сворачивается в круг со стрелкой.
   *
   * Со словом «Отправить» она занимала 118 точек из 352, и полю ввода
   * оставалось 149 — уже, чем сама кнопка. Писать в такое поле нельзя: видно
   * последние два слова.
   */
  .composer__send-word {
    display: none;
  }

  .composer__send-sign {
    display: block;
    font-size: 1.15rem;
    line-height: 1;
  }

  .composer__send {
    display: grid;
    place-items: center;
    width: 2.75rem;
    height: 2.75rem;
    padding: 0;
    border-radius: 50%;
  }

  /* Поле забирает всё, что осталось: без min-width оно не ужимается ниже
     своего содержимого и выдавливает соседей. */
  .composer__field {
    flex: 1;
    min-width: 0;
  }

  /* Лист выезжает снизу — там, где до него дотягивается большой палец. */
  .sheet {
    place-items: end stretch;
    padding: 0;
  }

  .sheet__panel {
    width: 100%;
    max-height: 85dvh;
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  }
}
</style>