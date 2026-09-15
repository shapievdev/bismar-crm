<script setup lang="ts">
import type { Recording } from '~/composables/useVoiceRecorder'
import type { ChatMessage, ChatPerson, MessageAbout, VoiceNumbers } from '~/types/chat'
import { formatBytes } from '~/utils/bytes'
import { plainMessageText } from '~/utils/messageText'

/**
 * Поле ввода со всем, что к нему прилагается.
 *
 * Собрано вокруг одного правила: отправка ничего не ждёт. Поле освобождается в
 * тот же миг, а реплика уходит сама и сама показывает, сколько байт улетело, —
 * иначе отправка двадцатимегабайтного видео выглядит как зависший мессенджер.
 *
 * Начертания набирают знаками, как в телеграме и в markdown, и те же знаки
 * ставят горячие клавиши. Своего редактора здесь нет намеренно: сказанное
 * хранится обычным текстом, и всё, что не разобралось, остаётся читаемым и в
 * уведомлении, и в поиске.
 */
const props = defineProps<{
  conversationId: number
  /** Участники — по ним работает подсказка упоминаний. */
  people: ChatPerson[]
  me: number | null
  /** На что отвечаем; null — ни на что. */
  replyTo: ChatMessage | null
  /** Что переписываем; null — пишем новое. */
  editing: ChatMessage | null
  /** Материал, с которого сюда пришли, — карточкой над полем. */
  about: MessageAbout | null
}>()

const emit = defineEmits<{
  send: [payload: { body: string, files: File[], mentions: number[], voice: VoiceNumbers | null }]
  save: [body: string]
  cancelComposing: []
  dropAbout: []
  typing: []
}>()

const drafts = useChatDrafts()

const draft = ref('')
const files = ref<File[]>([])
const warning = ref<string | null>(null)
const emojiOpen = ref(false)
const attachOpen = ref(false)
const recording = ref(false)
const dragging = ref(false)

/**
 * Кого позвали в этой реплике.
 *
 * Номера копятся по мере того, как упоминания выбирают из подсказки, и перед
 * отправкой сверяются с текстом: набранное и стёртое обратно звать никого не
 * должно.
 */
const called = ref<number[]>([])

const field = ref<HTMLTextAreaElement | null>(null)
const mediaPicker = ref<HTMLInputElement | null>(null)
const paperPicker = ref<HTMLInputElement | null>(null)

/**
 * Пределы — те же, что у сервера: пять файлов, двадцать мегабайт на каждый.
 *
 * Проверяются и здесь: отправить видео с телефона и узнать через минуту
 * загрузки, что оно вдвое тяжелее допустимого, — худшее из возможных сообщений
 * об ошибке.
 */
const MAX_FILES = 5
const MAX_FILE_BYTES = 20 * 1024 * 1024

/* ---------- Черновик ---------- */

// Открыли другой разговор — в поле встаёт то, что было начато в нём.
watch(() => props.conversationId, (id, was) => {
  if (was) {
    drafts.write(was, draft.value)
  }

  draft.value = drafts.read(id)
  files.value = []
  called.value = []
  warning.value = null

  void nextTick(fit)
}, { immediate: true })

// Уходя со страницы, дописываем начатое: вкладку закрывают и посреди слова.
onBeforeUnmount(() => {
  if (!props.editing) {
    drafts.write(props.conversationId, draft.value)
  }
})

/* ---------- Правка ---------- */

watch(() => props.editing, (message) => {
  if (message) {
    draft.value = message.body ?? ''
    void nextTick(() => {
      fit()
      field.value?.focus()
    })
  }
  else {
    // Бросили правку — поле возвращается к тому, что в нём было до неё.
    draft.value = drafts.read(props.conversationId)
    void nextTick(fit)
  }
})

watch(() => props.replyTo, (message) => {
  if (message) {
    field.value?.focus()
  }
})

/* ---------- Высота поля ---------- */

/**
 * Поле растёт под сообщение.
 *
 * На телефоне это важнее, чем на столе: строка в одну высоту прячет от пишущего
 * всё, кроме последних слов, а ручку растягивания там не ухватить.
 */
/** Выше этого поле не растёт: дальше набранное листают внутри него. */
const MAX_FIELD_HEIGHT = 160

function fit(): void {
  const element = field.value

  if (!element) {
    return
  }

  element.style.height = 'auto'

  const wanted = element.scrollHeight

  element.style.height = `${Math.min(wanted, MAX_FIELD_HEIGHT)}px`

  /*
   * Полоса прокрутки — только когда есть что прокручивать.
   *
   * Поле подгоняется под текст с точностью до целой точки, и на одной строке
   * желаемая высота то и дело оказывается на полточки больше выставленной.
   * Браузеру этого хватает, чтобы показать полосу прокрутки: серая черта у
   * правого края пустого поля, которую нечем объяснить.
   */
  element.style.overflowY = wanted > MAX_FIELD_HEIGHT ? 'auto' : 'hidden'
}

watch(draft, () => {
  void nextTick(fit)

  if (!props.editing) {
    drafts.write(props.conversationId, draft.value)
  }
})

/* ---------- Отправка ---------- */

const canSend = computed(() => (props.editing
  ? draft.value.trim().length > 0
  : draft.value.trim().length > 0 || files.value.length > 0))

function submit(): void {
  if (props.editing) {
    if (draft.value.trim()) {
      emit('save', draft.value.trim())
    }

    return
  }

  if (!canSend.value) {
    return
  }

  const body = draft.value.trim()

  // Из позванных остаются те, чьё имя в реплике действительно осталось: набрали
  // упоминание и стёрли — звать больше некого.
  const mentions = called.value.filter((id) => {
    const person = props.people.find(one => one.id === id)

    return person !== undefined && (body.includes(`@${person.name}`) || body.includes(`@${person.short_name}`))
  })

  emit('send', { body, files: files.value, mentions, voice: null })

  draft.value = ''
  files.value = []
  called.value = []
  warning.value = null
  drafts.drop(props.conversationId)

  void nextTick(fit)
}

/* ---------- Голосовое ---------- */

function onRecorded(recorded: Recording): void {
  recording.value = false

  emit('send', {
    body: '',
    files: [recorded.file],
    mentions: [],
    voice: recorded.numbers,
  })
}

/* ---------- Вложения ---------- */

function pickFiles(event: Event): void {
  const input = event.target as HTMLInputElement
  const chosen = input.files ? [...input.files] : []

  input.value = ''
  attachOpen.value = false

  add(chosen)
}

/**
 * Добавляет выбранное к тому, что уже приложено.
 *
 * Добавляет, а не подменяет: снимки выбирают из галереи, документ — из файлов, и
 * это два разных выбора в одном сообщении.
 */
function add(chosen: File[]): void {
  warning.value = null

  const heavy = chosen.find(file => file.size > MAX_FILE_BYTES)

  if (heavy) {
    warning.value = `«${heavy.name}» тяжелее ${MAX_FILE_BYTES / 1024 / 1024} МБ — такое кладут в файлы урока.`

    return
  }

  const room = MAX_FILES - files.value.length

  if (chosen.length > room) {
    warning.value = `За раз уходит не больше ${MAX_FILES} файлов.`
  }

  files.value = [...files.value, ...chosen.slice(0, Math.max(0, room))]
}

function dropFile(index: number): void {
  files.value = files.value.filter((_, at) => at !== index)
  warning.value = null
}

/**
 * Снимок из буфера.
 *
 * Скриншот в рабочей переписке — самое частое вложение вообще, и заставлять
 * сохранять его файлом ради отправки незачем. Имя придумываем сами: у
 * вставленного из буфера его нет.
 */
function onPaste(event: ClipboardEvent): void {
  const pasted = [...(event.clipboardData?.items ?? [])]
    .filter(item => item.kind === 'file')
    .map(item => item.getAsFile())
    .filter((file): file is File => file !== null)

  if (pasted.length === 0) {
    return
  }

  event.preventDefault()

  add(pasted.map(file => (file.name && file.name !== 'image.png'
    ? file
    : new File([file], `Снимок ${new Date().toLocaleString('ru-RU')}.png`, { type: file.type }))))
}

/* ---------- Перетаскивание ---------- */

function onDrop(event: DragEvent): void {
  dragging.value = false

  const dropped = [...(event.dataTransfer?.files ?? [])]

  if (dropped.length) {
    add(dropped)
  }
}

/* ---------- Начертания ---------- */

/**
 * Оборачивает выделенное знаками — или ставит пустую пару, если не выделено.
 *
 * Курсор после этого встаёт внутрь пары: следом человек пишет то, что собирался
 * выделить, а не ищет, куда ткнуть.
 */
function wrap(sign: string): void {
  const element = field.value

  if (!element) {
    return
  }

  const from = element.selectionStart
  const to = element.selectionEnd
  const chosen = draft.value.slice(from, to)

  draft.value = draft.value.slice(0, from) + sign + chosen + sign + draft.value.slice(to)

  void nextTick(() => {
    element.focus()
    element.setSelectionRange(from + sign.length, from + sign.length + chosen.length)
  })
}

/* ---------- Упоминания ---------- */

/** Что набрано после последней собаки: по нему и отбирается подсказка. */
const mentionQuery = ref<string | null>(null)

const mentionOptions = computed(() => {
  const needle = mentionQuery.value

  if (needle === null) {
    return []
  }

  return props.people
    .filter(person => person.id !== props.me)
    .filter(person => needle === '' || person.name.toLowerCase().includes(needle.toLowerCase()))
    .slice(0, 6)
})

/**
 * Следит, не набирают ли упоминание.
 *
 * Собака считается началом упоминания, только если перед ней пробел или начало
 * строки: в почте она стоит посреди слова, и подсказка там ни к чему. Пробел
 * внутри набранного не обрывает поиск — фамилию и имя разделяет именно он.
 */
function trackMention(): void {
  const element = field.value

  if (!element || props.people.length === 0) {
    mentionQuery.value = null

    return
  }

  const upto = draft.value.slice(0, element.selectionStart)
  const at = upto.lastIndexOf('@')

  if (at === -1) {
    mentionQuery.value = null

    return
  }

  const before = at === 0 ? ' ' : upto[at - 1] ?? ' '
  const typed = upto.slice(at + 1)

  // Больше двух слов после собаки — это уже не имя, а продолжение фразы.
  mentionQuery.value = /\s/.test(before) && typed.split(/\s+/).length <= 2 && !typed.includes('\n')
    ? typed
    : null
}

function insertMention(person: ChatPerson): void {
  const element = field.value

  if (!element) {
    return
  }

  const upto = draft.value.slice(0, element.selectionStart)
  const at = upto.lastIndexOf('@')

  draft.value = `${draft.value.slice(0, at)}@${person.name} ${draft.value.slice(element.selectionStart)}`
  called.value = [...new Set([...called.value, person.id])]
  mentionQuery.value = null

  void nextTick(() => {
    const cursor = at + person.name.length + 2

    element.focus()
    element.setSelectionRange(cursor, cursor)
  })
}

/* ---------- Клавиатура ---------- */

function onKeydown(event: KeyboardEvent): void {
  // Пока открыта подсказка упоминаний, Enter выбирает из неё, а не отправляет.
  if (mentionOptions.value.length > 0 && event.key === 'Enter') {
    event.preventDefault()
    insertMention(mentionOptions.value[0]!)

    return
  }

  if (event.key === 'Escape') {
    if (mentionQuery.value !== null) {
      mentionQuery.value = null
    }
    else if (props.editing || props.replyTo) {
      emit('cancelComposing')
    }

    event.preventDefault()

    return
  }

  // Enter отправляет, Shift+Enter переносит строку — как во всяком чате.
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    submit()

    return
  }

  if (event.metaKey || event.ctrlKey) {
    const marks: Record<string, string> = { b: '**', i: '__', u: '__', s: '~~' }
    const sign = marks[event.key.toLowerCase()]

    if (sign) {
      event.preventDefault()
      wrap(sign)

      return
    }
  }

  announce()
}

/**
 * Оповещение о наборе — не чаще раза в две секунды.
 *
 * Каждое нажатие клавиши уходило бы в сокет отдельным пакетом, а собеседнику от
 * этого ни теплее ни холоднее: надпись «печатает» и так висит три секунды.
 */
let lastAnnounced = 0

function announce(): void {
  const now = Date.now()

  if (now - lastAnnounced > 2000) {
    lastAnnounced = now
    emit('typing')
  }
}

function closePopovers(): void {
  emojiOpen.value = false
  attachOpen.value = false
}

onMounted(() => document.addEventListener('click', closePopovers))
onBeforeUnmount(() => document.removeEventListener('click', closePopovers))

function insertEmoji(emoji: string): void {
  const element = field.value
  const at = element?.selectionStart ?? draft.value.length

  draft.value = draft.value.slice(0, at) + emoji + draft.value.slice(at)

  void nextTick(() => {
    element?.focus()
    element?.setSelectionRange(at + emoji.length, at + emoji.length)
  })
}
</script>

<template>
  <form
    class="composer"
    :class="{ 'composer--dragging': dragging }"
    @submit.prevent="submit"
    @dragover.prevent="dragging = true"
    @dragleave="dragging = false"
    @drop.prevent="onDrop"
  >
    <!-- Перетаскиваемое видно до того, как его отпустят: иначе непонятно, попадёт
         оно в переписку или в соседнюю вкладку. -->
    <div v-if="dragging" class="composer__drop">
      Отпустите — приложим к сообщению
    </div>

    <!-- С какого материала сюда пришли. Та же карточка встанет над отправленной
         репликой: адресат должен видеть, о чём вопрос. -->
    <div v-if="about" class="strip">
      <span class="strip__kind">{{ about.kind_label }}</span>
      <span class="strip__text">{{ about.title }}</span>
      <button type="button" class="strip__off" aria-label="Писать без материала" @click="emit('dropAbout')">
        ✕
      </button>
    </div>

    <div v-if="replyTo || editing" class="strip">
      <span class="strip__kind">{{ editing ? 'Изменение' : 'Ответ' }}</span>
      <span class="strip__text">
        {{ plainMessageText((editing ? editing.body : replyTo?.body) ?? '') || 'Вложение' }}
      </span>
      <button type="button" class="strip__off" aria-label="Отменить" @click="emit('cancelComposing')">
        ✕
      </button>
    </div>

    <p v-if="warning" class="composer__warning">
      {{ warning }}
    </p>

    <ul v-if="files.length" class="chosen">
      <li v-for="(file, index) in files" :key="`${file.name}-${index}`">
        <UiFileIcon :name="file.name" :mime-type="file.type" />
        <span class="chosen__name">{{ file.name }}</span>
        <span class="faint">{{ formatBytes(file.size) }}</span>
        <button type="button" :aria-label="`Убрать ${file.name}`" @click="dropFile(index)">
          ✕
        </button>
      </li>
    </ul>

    <ChatRecorder v-if="recording" @done="onRecorded" @cancel="recording = false" />

    <div v-else class="row">
      <!-- При правке скрепка убрана: правка меняет слова, а приложить файл
           задним числом — это новое сообщение. -->
      <div v-if="!editing" class="tool">
        <button
          type="button"
          class="tool__button"
          :aria-expanded="attachOpen"
          aria-label="Приложить"
          @click.stop="attachOpen = !attachOpen; emojiOpen = false"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path
              d="M15.2 7L8.6 13.6a2.1 2.1 0 0 0 3 3l6.4-6.6a4.2 4.2 0 1 0-6-5.9l-6.4 6.6a6.3 6.3 0 1 0 8.9 8.9l6.2-6.3"
              stroke="currentColor"
              stroke-width="1.9"
              stroke-linecap="round"
              stroke-linejoin="round"
              fill="none"
            />
          </svg>
        </button>

        <!-- Два входа, а не один общий выбор файлов: на телефоне «фото или
             видео» открывает галерею и камеру, а «файл» — хранилище, и это
             разные места. -->
        <div v-if="attachOpen" class="pop" @click.stop>
          <button type="button" class="pop__option" @click="mediaPicker?.click()">
            Фото или видео
          </button>
          <button type="button" class="pop__option" @click="paperPicker?.click()">
            Файл
          </button>
        </div>

        <input ref="mediaPicker" type="file" accept="image/*,video/*" multiple hidden @change="pickFiles">
        <input ref="paperPicker" type="file" multiple hidden @change="pickFiles">
      </div>

      <div class="field">
        <!-- Подсказка упоминаний стоит над полем: снизу её закрывала бы
             клавиатура телефона. -->
        <ul v-if="mentionOptions.length" class="mentions">
          <li v-for="person in mentionOptions" :key="person.id">
            <button type="button" class="mentions__option" @mousedown.prevent="insertMention(person)">
              <UserAvatar :name="person.name" :src="person.avatar_url" :size="24" />
              <span>{{ person.name }}</span>
            </button>
          </li>
        </ul>

        <textarea
          ref="field"
          v-model="draft"
          class="field__area"
          rows="1"
          maxlength="5000"
          :placeholder="editing ? 'Изменить сообщение…' : 'Сообщение…'"
          @keydown="onKeydown"
          @keyup="trackMention"
          @click="trackMention"
          @paste="onPaste"
        />
      </div>

      <div class="tool">
        <button
          type="button"
          class="tool__button"
          :aria-expanded="emojiOpen"
          aria-label="Знаки"
          @click.stop="emojiOpen = !emojiOpen; attachOpen = false"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8" fill="none" />
            <circle cx="9.2" cy="10" r="1.1" fill="currentColor" />
            <circle cx="14.8" cy="10" r="1.1" fill="currentColor" />
            <path d="M8.6 14.4a4.2 4.2 0 0 0 6.8 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" fill="none" />
          </svg>
        </button>

        <ChatEmojiPicker v-if="emojiOpen" @pick="insertEmoji" @close="emojiOpen = false" />
      </div>

      <!--
        Кнопка одна и меняется по тому, что в поле: пусто — микрофон, набрали
        текст — стрелка. Так у самого частого действия всегда одно и то же место,
        и целиться в него не надо.
      -->
      <button
        v-if="canSend || editing"
        type="submit"
        class="send"
        :disabled="!canSend"
        :aria-label="editing ? 'Сохранить' : 'Отправить'"
      >
        <svg v-if="editing" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M5 12.5l4.5 4.5L19 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none" />
        </svg>
        <!--
          Бумажный самолётик носом вправо — туда, куда уходит сообщение.
          С выемкой на хвосте: без неё остаётся треугольник, а треугольник в
          кружке читается как «играть».
        -->
        <svg v-else class="send__plane" viewBox="0 0 24 24" aria-hidden="true">
          <path
            d="M3.4 20.4l17.45-7.48a1 1 0 0 0 0-1.84L3.4 3.6a.993.993 0 0 0-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"
            fill="currentColor"
          />
        </svg>
      </button>

      <button
        v-else
        type="button"
        class="send send--voice"
        aria-label="Записать голосовое"
        @click="recording = true"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <rect x="9" y="3" width="6" height="11" rx="3" stroke="currentColor" stroke-width="1.9" fill="none" />
          <path d="M5.5 11.5a6.5 6.5 0 0 0 13 0M12 18v3" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" fill="none" />
        </svg>
      </button>
    </div>
  </form>
</template>

<style scoped>
.composer {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 0.6rem var(--pane-pad) calc(0.6rem + env(safe-area-inset-bottom, 0px));
  border-top: 1px solid var(--color-border);
}

.composer--dragging {
  background: var(--color-accent-soft);
}

.composer__drop {
  position: absolute;
  inset: 0.3rem;
  z-index: 5;
  display: grid;
  place-items: center;
  border: 2px dashed var(--color-accent);
  border-radius: var(--radius);
  background: var(--color-surface);
  font-size: 0.9rem;
  pointer-events: none;
}

.composer__warning {
  margin: 0;
  color: var(--color-warning);
  font-size: 0.8rem;
}

/* Полоса над полем: на что отвечаем, что правим, с какого материала пишем. */
.strip {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.3rem 0.5rem;
  border-left: 2px solid var(--color-accent);
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  background: var(--color-surface-sunken);
  animation: slip 0.16s ease-out;
}

@keyframes slip {
  from { opacity: 0; transform: translateY(0.3rem); }
  to { opacity: 1; transform: none; }
}

.strip__kind {
  flex-shrink: 0;
  color: var(--color-accent);
  font-size: 0.72rem;
  font-weight: 600;
}

.strip__text {
  overflow: hidden;
  flex: 1;
  font-size: 0.8rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.strip__off,
.chosen button {
  flex-shrink: 0;
  padding: 0 0.2rem;
  border: none;
  background: none;
  color: var(--color-text-faint);
  font: inherit;
  cursor: pointer;
}

.chosen {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.chosen li {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.25rem 0.4rem;
  border-radius: var(--radius-sm);
  background: var(--color-surface-sunken);
  font-size: 0.8rem;
}

.chosen__name {
  overflow: hidden;
  flex: 1;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.row {
  display: flex;
  align-items: flex-end;
  gap: 0.3rem;
}

.tool {
  position: relative;
  display: flex;
  flex-shrink: 0;
}

.tool__button {
  display: grid;
  place-items: center;
  width: 2.3rem;
  height: 2.3rem;
  padding: 0;
  border: none;
  border-radius: 50%;
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease;
}

.tool__button:hover,
.tool__button[aria-expanded='true'] {
  background: var(--control-surface-hover);
  color: var(--color-text);
}

.tool__button svg {
  width: 1.3rem;
  height: 1.3rem;
}

.pop {
  position: absolute;
  bottom: calc(100% + 0.4rem);
  left: 0;
  z-index: 20;
  display: flex;
  min-width: 10rem;
  flex-direction: column;
  padding: 0.25rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface-raised);
  box-shadow: var(--shadow-lg);
  animation: grow 0.14s ease-out;
}

@keyframes grow {
  from { opacity: 0; transform: scale(0.94) translateY(0.3rem); }
  to { opacity: 1; transform: none; }
}

.pop__option {
  padding: 0.45rem 0.6rem;
  border: none;
  border-radius: var(--radius-sm);
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 0.88rem;
  text-align: left;
  cursor: pointer;
}

.pop__option:hover {
  background: var(--control-surface-hover);
}

.field {
  position: relative;
  min-width: 0;
  flex: 1;
}

.field__area {
  display: block;
  width: 100%;
  max-height: 10rem;
  padding: 0.5rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-bg);
  color: inherit;
  font: inherit;
  font-size: 0.92rem;
  line-height: 1.45;
  resize: none;
}

.field__area:focus {
  border-color: var(--color-border-strong);
  outline: none;
}

.mentions {
  position: absolute;
  bottom: calc(100% + 0.35rem);
  left: 0;
  z-index: 20;
  width: min(18rem, 100%);
  max-height: 12rem;
  overflow-y: auto;
  margin: 0;
  padding: 0.25rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface-raised);
  box-shadow: var(--shadow-lg);
  list-style: none;
  animation: grow 0.14s ease-out;
}

.mentions__option {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.45rem;
  border: none;
  border-radius: var(--radius-sm);
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 0.85rem;
  text-align: left;
  cursor: pointer;
}

.mentions__option:hover {
  background: var(--control-surface-hover);
}

/* Кружок крупнее прочих кнопок: это самое частое действие на экране, и в него
   целятся, не глядя. */
.send {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 2.6rem;
  height: 2.6rem;
  padding: 0;
  border: none;
  border-radius: 50%;
  background: var(--color-accent);
  color: var(--color-accent-text);
  cursor: pointer;
  transition: transform 0.12s ease, opacity 0.15s ease, background-color 0.15s ease;
}

.send:hover:not(:disabled) {
  background: var(--color-accent-hover);
}

.send:disabled {
  opacity: 0.45;
  cursor: default;
}

.send:active:not(:disabled) {
  transform: scale(0.92);
}

.send svg {
  width: 1.2rem;
  height: 1.2rem;
}

/*
 * Самолётик сдвинут на волосок вправо и вверх.
 *
 * Нарисованный по центру кружка, он кажется осевшим влево: у фигуры с выемкой
 * на хвосте вес смещён к носу, и геометрический центр не совпадает с видимым.
 */
.send__plane {
  transform: translate(1px, -1px);
}

/* Микрофон стоит на том же месте, но не зовёт: голосовое — не то действие,
   которое предлагают первым. */
.send--voice {
  background: transparent;
  color: var(--color-text-muted);
}

.send--voice:hover {
  background: var(--control-surface-hover);
  color: var(--color-text);
}

.send--voice svg {
  width: 1.3rem;
  height: 1.3rem;
}

@media (prefers-reduced-motion: reduce) {
  .strip,
  .pop,
  .mentions {
    animation: none;
  }

  .tool__button,
  .send {
    transition: none;
  }

  .send:active:not(:disabled) {
    transform: none;
  }
}
</style>
