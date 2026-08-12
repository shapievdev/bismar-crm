<script setup lang="ts">
import type { ChatMessage, ChatPerson } from '~/types/chat'

// `fills`: страница меряет себя по экрану, а не растёт с содержимым, поэтому
// оболочка снимает свой нижний отступ — см. `fit()` ниже.
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
    await nextTick()
    fit()

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
    await nextTick()
    fit()
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

const canSend = computed(() => (draft.value.trim().length > 0 || files.value.length > 0) && !isSending.value)

async function submit(): Promise<void> {
  if (!canSend.value) {
    return
  }

  isSending.value = true

  try {
    await messenger.send(draft.value.trim(), files.value)
    draft.value = ''
    files.value = []

    if (filePicker.value) {
      filePicker.value.value = ''
    }

    await scrollToEnd()
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

/**
 * Мессенджер занимает ровно то, что осталось от экрана.
 *
 * Не `calc(100vh - 8rem)`: сверху лежит шапка, отступ страницы и — на телефоне
 * — ещё и рельса разделов, и все эти величины разные на разных ширинах.
 * Угаданное число промахивается, а промах здесь виден сразу: поле ввода
 * уезжает под нижний край, и написать нельзя, пока не прокрутишь.
 *
 * Меряем от собственной верхней кромки до низа видимой области. На телефоне
 * это ещё и единственный способ пережить выезжающую адресную строку и
 * клавиатуру: visualViewport меняется вместе с ними, а 100vh — нет.
 */
const shell = useTemplateRef<HTMLElement>('shell')

function fit(): void {
  const element = shell.value

  if (!element) {
    return
  }

  const viewport = window.visualViewport

  /*
   * Обе величины обязаны быть в одной системе отсчёта, и это главное здесь.
   *
   * `getBoundingClientRect().top` отмеряется от разметочной области, а
   * `visualViewport.height` — от видимой. Пока клавиатуры нет, они совпадают и
   * разницы не видно. Стоит ей выехать, видимая область сжимается и уезжает
   * вниз внутри разметочной, и вычитание одного из другого начинает врать
   * ровно на высоту клавиатуры. Приводим верх к видимой области через
   * `offsetTop` — тогда высота верна в обоих состояниях.
   *
   * Прежняя поправка на «лишнюю» длину документа отсюда убрана: она сравнивала
   * `scrollHeight` разметочной области с высотой видимой и потому вычитала
   * клавиатуру второй раз — лента схлопывалась, а поле ввода оказывалось
   * посреди экрана. Причину поправки лечит `fills` в мета-данных страницы:
   * под мессенджером просто не остаётся отступа, который нужно было бы
   * компенсировать.
   */
  const height = viewport?.height ?? window.innerHeight
  const top = element.getBoundingClientRect().top - (viewport?.offsetTop ?? 0)

  element.style.height = `${Math.max(320, height - top)}px`
}

onMounted(() => {
  fit()
  window.addEventListener('resize', fit)
  window.visualViewport?.addEventListener('resize', fit)
  // Пока клавиатура открыта, видимая область ещё и ездит внутри разметочной,
  // а событие об этом приходит как прокрутка, не как изменение размера.
  window.visualViewport?.addEventListener('scroll', fit)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', fit)
  window.visualViewport?.removeEventListener('resize', fit)
  window.visualViewport?.removeEventListener('scroll', fit)
})

/* ---------- Прокрутка ленты ---------- */

const thread = useTemplateRef<HTMLElement>('thread')

async function scrollToEnd(): Promise<void> {
  await nextTick()

  if (thread.value) {
    thread.value.scrollTop = thread.value.scrollHeight
  }
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
  <section ref="shell" class="messenger" :class="{ 'messenger--open': activeId !== null }">
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

            <div v-else class="bubble" :class="{ 'bubble--mine': isMine(message) }">
              <span v-if="active.is_group && !isMine(message)" class="bubble__author">
                {{ message.author?.name ?? 'Бывший сотрудник' }}
              </span>

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
                {{ time(message.created_at) }}
                <!-- Вторая галочка — когда собеседник дочитал до этого места. -->
                <template v-if="isMine(message)">{{ isSeen(message) ? '✓✓' : '✓' }}</template>
              </span>
            </div>
          </template>
        </div>

        <form class="composer" @submit.prevent="submit">
          <ul v-if="files.length" class="composer__files">
            <li v-for="(file, index) in files" :key="`${file.name}-${index}`">
              {{ file.name }}
              <button type="button" @click="dropFile(index)">
                ✕
              </button>
            </li>
          </ul>

          <div class="composer__row">
            <label class="composer__clip" title="Приложить файл">
              📎
              <input ref="filePicker" type="file" multiple hidden @change="pickFiles">
            </label>

            <textarea
              ref="field"
              v-model="draft"
              class="input composer__field"
              rows="1"
              maxlength="5000"
              placeholder="Сообщение…"
              @keydown="onKeydown"
            />

            <button type="submit" class="button-primary" :disabled="!canSend">
              {{ isSending ? '…' : 'Отправить' }}
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
  /* Настоящую высоту ставит fit() при открытии и на каждое изменение экрана;
     здесь — на случай, если сценарий ещё не отработал. */
  height: 70dvh;
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
  display: flex;
  flex-direction: column;
  align-self: flex-start;
  gap: 0.15rem;
  max-width: min(34rem, 78%);
  padding: 0.5rem 0.75rem;
  border-radius: var(--radius-lg);
  background: var(--color-surface-sunken);
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
  align-self: flex-end;
  font-size: 0.7rem;
  opacity: 0.7;
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