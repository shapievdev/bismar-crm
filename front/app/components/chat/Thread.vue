<script setup lang="ts">
import type { ChatPerson, MessageAttachment, ThreadMessage } from '~/types/chat'
import { chatDay } from '~/utils/chatTime'

/**
 * Лента разговора.
 *
 * Отвечает за одно: где что стоит и куда прокручено. Что сделать с репликой,
 * решает страница — сюда события только уходят.
 *
 * Прокрутка здесь — самое тонкое место во всём мессенджере. Правил три.
 * Открыли — стоим внизу либо на первом непрочитанном. Пришло новое — едем вниз
 * только если и так были внизу: иначе лента дёргается под руками у того, кто
 * читает прошлое. Догрузили прошлое — остаёмся на том же сообщении, а не там же
 * по высоте.
 */
const props = defineProps<{
  messages: ThreadMessage[]
  people: ChatPerson[]
  me: number | null
  isGroup: boolean
  /** С какой реплики начинается непрочитанное: над ней встаёт отбивка. */
  firstUnreadId: number | null
  hasOlder: boolean
  hasNewer: boolean
  /** Подсвеченная реплика — та, к которой только что перескочили. */
  highlighted: number | null
  selecting: boolean
  selected: number[]
  /** Кто дочитал докуда: по этому считается вторая галочка. */
  readers: ChatPerson[]
  /** Играющее голосовое — одно на всю ленту. */
  playingVoice: number | null
  /*
   * Догрузка — функциями, а не событиями, и это не вкусовщина.
   *
   * Дочитав до верха, лента обязана остаться на том же сообщении: для этого она
   * запоминает высоту до догрузки и вычитает её после. «После» здесь означает
   * «когда сорок сообщений уже приехали и встали в разметку», а событие
   * возвращает управление сразу — ждать его нечем, и лента прыгала на сорок
   * реплик назад.
   */
  loadOlder: () => Promise<void>
  loadNewer: () => Promise<void>
}>()

const emit = defineEmits<{
  reply: [message: ThreadMessage]
  menu: [message: ThreadMessage, at: { x: number, y: number }]
  react: [message: ThreadMessage, emoji: string]
  toggleSelect: [message: ThreadMessage]
  jump: [messageId: number]
  openFile: [file: MessageAttachment]
  mention: [personId: number]
  playVoice: [attachmentId: number]
  pauseVoice: []
  retry: [localId: number]
  cancel: [localId: number]
  /** Человек ушёл от низа ленты: страница показывает кнопку «вниз». */
  atBottom: [value: boolean]
}>()

const strip = ref<HTMLElement | null>(null)
const content = ref<HTMLElement | null>(null)

/** Считаем «внизу» с запасом: последняя строка редко попадает ровно в край. */
const BOTTOM_SLACK = 150

const isAtBottom = ref(true)

/**
 * Держимся ли за низ ленты.
 *
 * Не то же, что «сейчас внизу». Лента дорастает уже после того, как мы до неё
 * долистали: снимок приходит без известных размеров, и его высота появляется,
 * когда файл загрузился, — а это секунды. К этому мгновению «сейчас внизу» уже
 * ложь, хотя человек никуда не уходил и ждёт увидеть последнее сказанное.
 *
 * Поэтому отдельный признак: ставится, когда мы сами перенесли ленту вниз, и
 * снимается только тогда, когда человек листает прочь.
 */
const pinned = ref(true)

defineExpose({ scrollToEnd, scrollTo: scrollToMessage, isAtBottom })

/**
 * Как прокручивать: плавно или разом.
 *
 * `instant`, а не `auto`: последнее означает «как сказано в стилях», и
 * мгновенного прыжка из него не выходит.
 *
 * Плавность, о которой просят, ещё и спрашивается у самого человека: браузер за
 * нас про «поменьше движения» здесь не помнит, а прокрутка на тысячу точек —
 * самое заметное движение во всём мессенджере.
 */
function scrollWay(smooth: boolean): ScrollBehavior {
  const calm = import.meta.client && window.matchMedia('(prefers-reduced-motion: reduce)').matches

  return smooth && !calm ? 'smooth' : 'instant'
}

async function scrollToEnd(smooth = false): Promise<void> {
  await nextTick()

  const element = strip.value

  if (element) {
    element.scrollTo({ top: element.scrollHeight, behavior: scrollWay(smooth) })
    pinned.value = true
  }
}

/**
 * Лента выросла — догоняем низ, если за него держались.
 *
 * Именно так чинится «открыл переписку, а она не на последнем сообщении»:
 * снимки, записи и карточки ссылок занимают место не сразу, и прокрутка,
 * сделанная до их появления, остаётся выше конца на их высоту. Наблюдатель
 * догоняет каждый раз, когда это происходит, — и перестаёт, стоит человеку
 * самому уйти листать прошлое.
 */
let watcher: ResizeObserver | null = null

onMounted(() => {
  if (!content.value || typeof ResizeObserver === 'undefined') {
    return
  }

  watcher = new ResizeObserver(() => {
    if (!pinned.value || !strip.value) {
      return
    }

    /*
     * Без плавности, и это важнее, чем кажется.
     *
     * Плавная прокрутка здесь не только выглядела бы как самопроизвольное
     * движение ленты — она бы сама себя и оборвала: пока идёт её анимация,
     * события прокрутки приходят с промежуточными положениями, «мы внизу»
     * становится ложью, и удержание снимается на полпути. Ровно на этом
     * ломалась догонка подгружаемых снимков.
     */
    strip.value.scrollTo({ top: strip.value.scrollHeight, behavior: 'instant' })
  })

  watcher.observe(content.value)
})

onBeforeUnmount(() => {
  watcher?.disconnect()
  watcher = null
})

/**
 * Переносит к реплике, если она уже загружена.
 *
 * Догружать ради этого всё, что было между, можно очень долго — цитата могла
 * быть годичной давности. Не нашли — сообщаем об этом наверх: там знают, что
 * делать, — сходить за ней на сервер.
 */
async function scrollToMessage(messageId: number, smooth = true): Promise<boolean> {
  await nextTick()

  const target = strip.value?.querySelector(`[data-message="${messageId}"]`)

  if (!target) {
    return false
  }

  target.scrollIntoView({ behavior: scrollWay(smooth), block: 'center' })

  return true
}

/**
 * Дочитали до края — догружаем.
 *
 * Вверх: сохраняем место по высоте прошлого содержимого, иначе лента прыгает на
 * сорок сообщений назад. Вниз: то же самое не нужно — там дописывают в конец,
 * и место остаётся на месте само.
 */
let loading = false

async function onScroll(): Promise<void> {
  const element = strip.value

  if (!element) {
    return
  }

  const bottom = element.scrollHeight - element.scrollTop - element.clientHeight
  const nowAtBottom = bottom < BOTTOM_SLACK

  if (nowAtBottom !== isAtBottom.value) {
    isAtBottom.value = nowAtBottom
    emit('atBottom', nowAtBottom)
  }

  // Ушёл листать прошлое — отпускаем низ. Вернулся к нему — держимся снова.
  pinned.value = nowAtBottom

  if (loading) {
    return
  }

  if (element.scrollTop < 60 && props.hasOlder) {
    loading = true

    const was = element.scrollHeight

    try {
      await props.loadOlder()
      await nextTick()

      element.scrollTop = element.scrollHeight - was
    }
    finally {
      loading = false
    }

    return
  }

  if (bottom < 200 && props.hasNewer) {
    loading = true

    try {
      await props.loadNewer()
      await nextTick()
    }
    finally {
      loading = false
    }
  }
}

/** Отбивка с датой ставится там, где день сменился. */
function startsNewDay(at: number): boolean {
  const previous = props.messages[at - 1]
  const current = props.messages[at]

  return !previous || chatDay(previous.created_at) !== chatDay(current?.created_at)
}

/**
 * Имя автора над чужой репликой — только у первой в череде.
 *
 * Пять реплик подряд от одного человека не нужно подписывать пять раз: это
 * читается как пять разных голосов.
 */
function startsRun(at: number): boolean {
  const previous = props.messages[at - 1]
  const current = props.messages[at]

  return !previous
    || previous.kind === 'system'
    || previous.author?.id !== current?.author?.id
    || startsNewDay(at)
}

function isMine(message: ThreadMessage): boolean {
  return message.author?.id === props.me
}

/**
 * Прочитано ли сообщение всеми, кто в разговоре.
 *
 * В группе — именно всеми: «прочитано» при девятнадцати прочитавших из двадцати
 * означало бы, что двадцатый пропустил, а отправитель об этом не знает.
 */
function isSeen(message: ThreadMessage): boolean {
  const others = props.readers.filter(one => one.id !== props.me)

  return others.length > 0 && others.every(one =>
    one.last_read_at !== null
    && one.last_read_at !== undefined
    && message.created_at !== null
    && new Date(one.last_read_at) >= new Date(message.created_at),
  )
}
</script>

<template>
  <!--
    Прокручивается внешний, а растёт внутренний: наблюдатель за размером видит
    только свою собственную рамку, а рамка у прокручиваемого не меняется — как
    бы ни росло содержимое. Отдельный слой внутри и есть то, за чем следят.
  -->
  <div ref="strip" class="thread" @scroll.passive="onScroll">
    <div ref="content" class="thread__content">
      <p v-if="hasOlder" class="edge faint">
        Прокрутите вверх, чтобы догрузить прошлое
      </p>

      <TransitionGroup name="say">
        <template v-for="(message, at) in messages" :key="message.id">
          <p v-if="startsNewDay(at)" :key="`day-${message.id}`" class="day">
            <span>{{ chatDay(message.created_at) }}</span>
          </p>

          <!-- Отбивка «непрочитанные» остаётся на месте всё время, пока переписка
               открыта: прочитанной она становится сразу, и исчезни отбивка вместе
               с этим, человек не успел бы понять, откуда читать. -->
          <p v-if="message.id === firstUnreadId" :key="`unread-${message.id}`" class="unread">
            <span>Непрочитанные</span>
          </p>

          <!-- Системная отметка: кто кого добавил, кто вышел, что закрепили. -->
          <p v-if="message.kind === 'system'" :key="`system-${message.id}`" class="system">
            {{ message.body }}
          </p>

          <ChatBubble
            v-else
            :key="`bubble-${message.id}`"
            :message="message"
            :mine="isMine(message)"
            :show-author="isGroup && !isMine(message) && startsRun(at)"
            :seen="isSeen(message)"
            :people="people"
            :me="me"
            :found="highlighted === message.id"
            :selecting="selecting"
            :selected="selected.includes(message.id)"
            :playing-voice="playingVoice"
            @reply="emit('reply', message)"
            @menu="emit('menu', message, $event)"
            @react="emit('react', message, $event)"
            @toggle-select="emit('toggleSelect', message)"
            @jump="emit('jump', $event)"
            @open-file="emit('openFile', $event)"
            @mention="emit('mention', $event)"
            @play-voice="emit('playVoice', $event)"
            @pause-voice="emit('pauseVoice')"
            @retry="emit('retry', message.id)"
            @cancel="emit('cancel', message.id)"
          />
        </template>
      </TransitionGroup>

      <p v-if="hasNewer" class="edge faint">
        Ниже есть ещё — прокрутите
      </p>
    </div>
  </div>
</template>

<style scoped>
/*
 * Внешний слой прокручивается и больше ничего не делает.
 *
 * Плавности здесь нет намеренно, хотя просится. `scroll-behavior: smooth`
 * распространяется на всякую прокрутку, в том числе на ту, которой лента
 * возвращает себя на место, — а такая анимация перебивается следующей и до
 * конца не доходит. Ровно из-за этого переписка открывалась в начале ленты, а
 * догрузка прошлого теряла место. Плавность теперь просят там, где она
 * уместна, — при перескоке к сообщению и по кнопке «вниз».
 */
.thread {
  min-height: 0;
  flex: 1;
  overflow-y: auto;
  /* Прокрутка держится за низ: пришедшее сообщение не сдвигает то, что человек
     читает. Браузер делает это сам, без счёта высот на каждом кадре. */
  overflow-anchor: auto;
}

/* Внутренний — вся раскладка ленты. Он же растёт, когда подгружаются снимки, и
   за ним следит наблюдатель размера. */
.thread__content {
  display: flex;
  min-height: 100%;
  flex-direction: column;
  gap: 0.3rem;
  padding: 0.8rem var(--pane-pad);
}

.edge {
  margin: 0.2rem 0;
  font-size: 0.75rem;
  text-align: center;
}

.day,
.unread {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin: 0.6rem 0 0.2rem;
  font-size: 0.72rem;
}

.day::before,
.day::after,
.unread::before,
.unread::after {
  content: '';
  height: 1px;
  flex: 1;
  background: var(--color-border);
}

.day span {
  padding: 0.1rem 0.6rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
}

.unread {
  color: var(--color-accent);
}

.unread::before,
.unread::after {
  background: color-mix(in srgb, var(--color-accent) 40%, transparent);
}

.unread span {
  font-weight: 600;
}

.system {
  margin: 0.3rem auto;
  padding: 0.2rem 0.7rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  font-size: 0.75rem;
  text-align: center;
}

/*
 * Появление реплики.
 *
 * Снизу и с лёгким набуханием — как будто она приходит из поля ввода, а не
 * возникает на пустом месте. Уход быстрее прихода: исчезающее не должно
 * задерживать взгляд.
 */
.say-enter-active {
  transition: opacity 0.22s ease, transform 0.26s cubic-bezier(0.22, 1, 0.36, 1);
}

.say-leave-active {
  position: absolute;
  transition: opacity 0.14s ease, transform 0.14s ease;
}

.say-enter-from {
  opacity: 0;
  transform: translateY(0.7rem) scale(0.97);
}

.say-leave-to {
  opacity: 0;
  transform: scale(0.96);
}

.say-move {
  transition: transform 0.24s ease;
}

@media (prefers-reduced-motion: reduce) {
  .say-enter-active,
  .say-leave-active,
  .say-move {
    transition: none;
  }
}
</style>
