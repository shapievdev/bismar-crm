<script setup lang="ts">
import type { FetchError } from 'ofetch'
import type {
  AnswerFeedback,
  ConsultantAnswer,
  ConsultantResolution,
  ConsultantSource,
} from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.view' })
useHead({ title: 'Консультант' })

const {
  ask,
  fetchConsultantHistory,
  forgetConsultantHistory,
  rateAnswer,
  requestFollowUp,
} = useLmsApi()

interface Exchange {
  id: number
  /**
   * Строка журнала, по которой ставится оценка и подаётся заявка.
   *
   * Отдельно от `id`: у свежего вопроса тот придуман на месте, чтобы список
   * было чем ключевать, пока ответ ещё едет, — а журнальный номер приходит
   * только вместе с ответом.
   */
  questionId?: number | null
  question: string
  /** Absent while the answer is still being written. */
  answer?: ConsultantAnswer
  /** Помог ли ответ, по словам самого спрашивавшего. */
  feedback?: AnswerFeedback | null
  /** Подана ли заявка на дополнение. */
  requested?: boolean
  /** Дописанный автором ответ — приходит после того, как заявку закрыли. */
  resolution?: ConsultantResolution
  error?: string
}

const exchanges = ref<Exchange[]>([])
const question = ref('')
const pending = ref(false)

/**
 * Прошлые разговоры этого сотрудника.
 *
 * Переписка живёт на сервере, а не в браузере: спросив с телефона, человек
 * ждёт увидеть тот же разговор за рабочим столом. Отказ не беда — чат работает
 * и без истории, поэтому молча начинаем с чистого листа.
 */
const isLoadingHistory = ref(true)

async function loadHistory(): Promise<void> {
  const { data } = await fetchConsultantHistory()

  exchanges.value = data.map(one => ({
    id: one.id,
    questionId: one.id,
    question: one.question,
    answer: one.answer,
    feedback: one.feedback,
    requested: one.requested,
    resolution: one.resolution,
  }))
}

onMounted(async () => {
  try {
    await loadHistory()
  }
  catch {
    // Ничего: пустой чат лучше сообщения об ошибке, которое читателю ни о чём.
  }
  finally {
    isLoadingHistory.value = false

    // Мгновенно, а не плавно: разговор открывается на последнем сообщении, и
    // проматывать к нему всю переписку на глазах у человека незачем. Плавная
    // прокрутка через сотню сообщений к тому же не доходит до конца — её
    // обрывает первое же касание колеса.
    await scrollToEnd({ smooth: false })
    await revealSupplement({ smooth: false })
  }

  window.document.addEventListener('visibilitychange', onReturn)
})

/**
 * Открывает переписку на дописанном ответе, если он появился.
 *
 * Дополнение встаёт туда, где вопрос был задан, — а это середина разговора, и
 * человек, для которого чат открывается на последнем сообщении, не увидит его
 * никогда. Ради этого «новым» оно и помечается: показать один раз и подвести к
 * нему глаза.
 */
async function revealSupplement({ smooth = true } = {}): Promise<void> {
  const fresh = exchanges.value.findLast(one => one.resolution?.is_new)

  if (!fresh || !import.meta.client) {
    return
  }

  await nextTick()

  window.document
    .getElementById(`exchange-${fresh.id}`)
    ?.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'center' })
}

onBeforeUnmount(() => {
  window.document.removeEventListener('visibilitychange', onReturn)
})

/**
 * Вернулись на вкладку — перечитываем переписку.
 *
 * Ради дополнений: автор отвечает на заявку часом позже, и висящая открытой
 * вкладка иначе показывала бы вчерашний разговор, пока её не перезагрузят.
 * Ни опроса, ни сокета для этого не нужно — человек всё равно возвращается
 * сюда сам.
 *
 * Пока ждём ответа на свежий вопрос — не трогаем: перезапись списка выбросила
 * бы вопрос, которого в истории ещё нет.
 */
function onReturn(): void {
  if (window.document.visibilityState !== 'visible' || pending.value) {
    return
  }

  void loadHistory()
    .then(() => revealSupplement())
    .catch(() => {
      // Ничего: на экране остаётся то, что уже прочитано.
    })
}

const canSend = computed(() => question.value.trim().length >= 3 && !pending.value)

async function send(): Promise<void> {
  const asked = question.value.trim()

  if (asked.length < 3 || pending.value) {
    return
  }

  const exchange: Exchange = { id: Date.now(), question: asked }

  exchanges.value.push(exchange)
  question.value = ''
  pending.value = true
  await scrollToEnd()

  try {
    const { data } = await ask(asked)

    exchange.answer = data
    exchange.questionId = data.id
  }
  catch (caught) {
    const failure = caught as FetchError<{ message?: string }>

    exchange.error = failure.data?.message ?? 'Не удалось получить ответ. Попробуйте ещё раз.'
  }
  finally {
    pending.value = false
    await scrollToEnd()
  }
}

/**
 * Keeps the newest exchange in view.
 *
 * The page scrolls, not the thread: the layout gives the main column its
 * content's height, so an inner scroller would have no height to scroll within.
 */
async function scrollToEnd({ smooth = true } = {}): Promise<void> {
  await nextTick()

  if (!import.meta.client) {
    return
  }

  // К низу страницы, а не к полю ввода. Поле закреплено внизу экрана и потому
  // всегда в зоне видимости — прокрутка к нему не делала ровно ничего, и
  // разговор открывался на первом сообщении вместо последнего.
  window.scrollTo({
    top: window.document.documentElement.scrollHeight,
    behavior: smooth ? 'smooth' : 'auto',
  })
}

/**
 * Очищает переписку.
 *
 * С подтверждением: восстановить её из интерфейса нельзя, а вопросы,
 * заданные за месяц, человек нередко перечитывает.
 */
const isClearing = ref(false)

async function clearHistory(): Promise<void> {
  if (!window.confirm('Очистить переписку с консультантом?')) {
    return
  }

  isClearing.value = true

  try {
    await forgetConsultantHistory()
    exchanges.value = []
  }
  catch {
    // Ничего: переписка осталась на месте, и это видно без сообщения.
  }
  finally {
    isClearing.value = false
  }
}

/**
 * Поле растёт под вопрос.
 *
 * Постоянная высота в одну строку прячет длинный вопрос от того, кто его
 * набирает, — а на телефоне, где ручку растягивания не ухватить, прячет
 * насовсем.
 */
const field = useTemplateRef<HTMLTextAreaElement>('field')

function fitField(): void {
  const element = field.value

  if (element) {
    element.style.height = 'auto'
    element.style.height = `${Math.min(element.scrollHeight, 160)}px`
  }
}

watch(question, () => nextTick(fitField))

/**
 * Enter sends, Shift+Enter breaks the line — the convention every chat uses.
 */
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault()
    void send()
  }
}

/** A run of plain text, or a citation standing in for one of the sources. */
type Part = { text: string } | { cite: ConsultantSource, index: number }

/**
 * Turns the `[источник 2]` markers into footnotes.
 *
 * The number is the source's place in the list the server returned, already
 * renumbered from one there, so it is also the footnote number. A marker
 * pointing past the end of that list is dropped rather than shown — the server
 * removes the ones it can account for, and this covers the rest.
 */
function parse(answer: ConsultantAnswer): Part[] {
  const parts: Part[] = []
  const pattern = /\[источник\s+(\d+)\]/gu
  let last = 0

  for (const match of answer.answer.matchAll(pattern)) {
    const at = match.index ?? 0
    const index = Number(match[1])
    const cite = answer.sources[index - 1]

    if (at > last) {
      parts.push({ text: answer.answer.slice(last, at) })
    }

    if (cite) {
      parts.push({ cite, index })
    }

    last = at + match[0].length
  }

  if (last < answer.answer.length) {
    parts.push({ text: answer.answer.slice(last) })
  }

  return parts
}

/**
 * Ссылка не на урок, а в место внутри него.
 *
 * Урок бывает на десять экранов текста и на час записи: ссылка на него целиком
 * означает «ищите сами». Строка таблицы знает секунду, страницу и абзац, и
 * именно это отличает её от найденного поиском куска.
 *
 * Файл — исключение: он лежит в хранилище, а не на странице урока, поэтому
 * ссылка ведёт прямо в него. Номер страницы передаётся якорем, который
 * понимают встроенные просмотрщики PDF.
 */
function sourceLink(source: ConsultantSource): string {
  const location = source.location

  if (location?.kind === 'attachment' && location.attachment_url) {
    return location.page === null
      ? location.attachment_url
      : `${location.attachment_url}#page=${location.page}`
  }

  const lesson = `/lms/${source.course_slug}/lessons/${source.lesson_id}`

  if (location?.kind === 'video' && location.seconds !== null) {
    return `${lesson}?t=${location.seconds}`
  }

  if (location?.kind === 'text' && location.block_id) {
    return `${lesson}?block=${location.block_id}`
  }

  return lesson
}

/** Ссылка на файл уходит из приложения — Nuxt такие обрабатывать не должен. */
function isExternal(source: ConsultantSource): boolean {
  return source.location?.kind === 'attachment' && Boolean(source.location.attachment_url)
}

/**
 * Оценка ответа.
 *
 * Помог — записываем и на этом заканчиваем: человек спросил и получил, просить
 * у него что-то ещё незачем. Не помог — предлагаем отправить заявку, и это уже
 * отдельный шаг, который он волен не делать.
 */
async function rate(exchange: Exchange, helpful: boolean): Promise<void> {
  const id = exchange.questionId

  if (!id || rating.value) {
    return
  }

  rating.value = id

  // Сразу, не дожидаясь сервера: оценка ничего не ломает, а кнопка, которая
  // думает полсекунды, читается как «не нажалось».
  exchange.feedback = helpful ? 'helpful' : 'unhelpful'

  try {
    await rateAnswer(id, helpful)
  }
  catch {
    exchange.feedback = null
  }
  finally {
    rating.value = null
    await scrollToEnd()
  }
}

const rating = ref<number | null>(null)

/**
 * Заявка на дополнение ответа.
 *
 * Пояснение необязательно: человек, которому ответили не о том, чаще всего не
 * умеет сказать, чего именно не хватило, — и требовать этого значит не
 * получить самой заявки.
 */
const notes = reactive<Record<number, string>>({})
const sending = ref<number | null>(null)

async function requestMore(exchange: Exchange): Promise<void> {
  const id = exchange.questionId

  if (!id || sending.value) {
    return
  }

  sending.value = id

  try {
    const { data } = await requestFollowUp(id, notes[id]?.trim() || undefined)

    exchange.requested = data.requested
    delete notes[id]
  }
  catch {
    // Ничего: заявка не ушла, кнопка осталась на месте — человек нажмёт снова.
  }
  finally {
    sending.value = null
    await scrollToEnd()
  }
}

/** Ссылка на урок, в который автор занёс дописанный ответ. */
function resolutionLink(resolution: ConsultantResolution): string {
  const lesson = resolution.lesson

  return lesson === null ? '' : `/lms/${lesson.course_slug}/lessons/${lesson.lesson_id}`
}
</script>

<template>
  <section class="consultant">
    <header class="head">
      <div>
        <h1 class="page-title">
          Консультант
        </h1>
        <p class="page-subtitle">
          Отвечает только по опубликованным материалам базы знаний и указывает, откуда взял каждое утверждение.
        </p>
      </div>
    </header>

    <!--
      Держится наверху, пока идёт разговор.

      Собственной высоты не имеет и событий не ловит: полоса лишь удерживает
      кнопку на месте, а разговор проходит под ней, не отодвинутый ни на
      пиксель.
    -->
    <div v-if="exchanges.length" class="pinned">
      <button
        type="button"
        class="clear"
        :disabled="isClearing"
        title="Убрать переписку с глаз. У авторов курсов вопросы останутся — по ним они видят, чего в базе не хватает"
        @click="clearHistory"
      >
        {{ isClearing ? 'Очищаем…' : 'Очистить' }}
      </button>
    </div>

    <div class="thread">
      <UiEmptyState
        v-if="!exchanges.length"
        title="Спросите что-нибудь о курсах"
        description="Например: как работать с возражением «дорого», или что входит в программу адаптации новичка."
      />

      <article
        v-for="exchange in exchanges"
        :id="`exchange-${exchange.id}`"
        :key="exchange.id"
        class="exchange"
      >
        <p class="asked">
          {{ exchange.question }}
        </p>

        <p v-if="exchange.error" class="alert alert--danger" role="alert">
          {{ exchange.error }}
        </p>

        <div v-else-if="!exchange.answer" class="card answer answer--pending">
          <span class="skeleton skeleton-line" />
          <span class="skeleton skeleton-line skeleton-line--short" />
        </div>

        <div v-else class="card answer">
          <p class="answer__text">
            <template v-for="(part, index) in parse(exchange.answer)" :key="index">
              <span v-if="'text' in part">{{ part.text }}</span>
              <NuxtLink
                v-else
                :to="sourceLink(part.cite)"
                :external="isExternal(part.cite)"
                class="cite"
                :title="`${part.cite.course_title} → ${part.cite.lesson_title}`"
              >{{ part.index }}</NuxtLink>
            </template>
          </p>

          <footer v-if="exchange.answer.sources.length" class="sources">
            <span class="sources__label">Источники</span>
            <NuxtLink
              v-for="(source, index) in exchange.answer.sources"
              :key="`${source.lesson_id}-${index}`"
              :to="sourceLink(source)"
              :external="isExternal(source)"
              :target="isExternal(source) ? '_blank' : undefined"
              :rel="isExternal(source) ? 'noopener noreferrer' : undefined"
              class="source"
            >
              <span class="source__index">{{ index + 1 }}</span>
              <span class="source__body">
                <span class="source__lesson">{{ source.lesson_title }}</span>
                <span class="faint">
                  {{ source.course_title }}
                  <!-- Место, а не только урок: «видео, 12:35» говорит читателю,
                       куда он попадёт, ещё до нажатия. -->
                  <template v-if="source.location">· {{ source.location.label }}</template>
                </span>
                <!-- Вопрос строки, если ответ пришёл из таблицы: видно, на какой
                     именно вопрос заготовлен этот ответ. -->
                <span v-if="source.question" class="source__question">{{ source.question }}</span>
                <!-- Цитата, а не пересказ: читатель видит исходные слова и
                     решает сам, отвечают ли они на его вопрос. -->
                <q v-if="source.quote" class="source__quote">{{ source.quote }}</q>
              </span>
            </NuxtLink>
          </footer>

          <!-- Материал по соседству. Отдельно от источников и без номеров:
               ответ на него не ссылается и за него не ручается — это то, что
               стоит открыть следом, а не то, чем отвечено. -->
          <footer v-if="exchange.answer.related.length" class="sources sources--related">
            <span class="sources__label">Смотрите также</span>
            <NuxtLink
              v-for="(source, index) in exchange.answer.related"
              :key="`related-${source.lesson_id}-${index}`"
              :to="sourceLink(source)"
              :external="isExternal(source)"
              :target="isExternal(source) ? '_blank' : undefined"
              :rel="isExternal(source) ? 'noopener noreferrer' : undefined"
              class="source"
            >
              <span class="source__body">
                <span class="source__lesson">{{ source.lesson_title }}</span>
                <span class="faint">
                  {{ source.course_title }}
                  <template v-if="source.location">· {{ source.location.label }}</template>
                </span>
                <span v-if="source.question" class="source__question">{{ source.question }}</span>
                <q v-if="source.quote" class="source__quote">{{ source.quote }}</q>
              </span>
            </NuxtLink>
          </footer>

          <!-- Ответа не нашлось — но нашлись живые люди, отвечающие за курс,
               о котором шла речь. Имена приставляет сервер, а не модель:
               названный ею человек мог бы оказаться выдуманным. -->
          <footer v-if="exchange.answer.experts?.length" class="sources sources--experts">
            <span class="sources__label">Спросите ответственного</span>
            <NuxtLink
              v-for="person in exchange.answer.experts"
              :key="person.user_id"
              :to="`/messenger?write=${person.user_id}`"
              class="source"
            >
              <UserAvatar :name="person.name" :src="person.avatar_url" :size="28" />
              <span class="source__body">
                <span class="source__lesson">{{ person.name }}</span>
                <span class="faint">Отвечает за курс «{{ person.course_title }}» — написать</span>
              </span>
            </NuxtLink>
          </footer>

          <!-- Помог ли ответ. Единственный сигнал, которого не даёт никакая
               эвристика: «ответил не о том» выглядит для журнала удачей —
               ссылки на месте, материал найден. -->
          <footer v-if="exchange.questionId && !exchange.resolution" class="verdict">
            <template v-if="!exchange.feedback">
              <span class="verdict__label">Ответ помог?</span>
              <button type="button" class="button-ghost verdict__button" @click="rate(exchange, true)">
                Да
              </button>
              <button type="button" class="button-ghost verdict__button" @click="rate(exchange, false)">
                Нет
              </button>
            </template>

            <!-- Помог — и на этом всё: просить у человека что-то ещё незачем. -->
            <span v-else-if="exchange.feedback === 'helpful'" class="faint">
              Спасибо, учтём.
            </span>

            <span v-else-if="exchange.requested" class="faint">
              Заявка отправлена — авторы дополнят материал, и ответ придёт сюда же.
            </span>

            <!-- Не помог: предлагаем заявку, но не требуем её. -->
            <div v-else class="appeal">
              <p class="appeal__lead">
                Отправить авторам заявку на дополнение ответа?
              </p>
              <textarea
                v-model="notes[exchange.questionId]"
                class="input appeal__note"
                rows="2"
                maxlength="500"
                placeholder="Чего не хватило? Можно не заполнять"
              />
              <button
                type="button"
                class="button-primary appeal__send"
                :disabled="sending === exchange.questionId"
                @click="requestMore(exchange)"
              >
                {{ sending === exchange.questionId ? 'Отправляю…' : 'Отправить заявку' }}
              </button>
            </div>
          </footer>
        </div>

        <!-- Ответ, дописанный автором по заявке. Отдельным сообщением, а не
             правкой прежнего: сотрудник должен увидеть, что произошло новое, а
             не гадать, изменилось ли что-то в старом. -->
        <div
          v-if="exchange.resolution"
          class="card supplement"
          :class="{ 'supplement--new': exchange.resolution.is_new }"
        >
          <header class="supplement__head">
            <span class="supplement__title">Автор дополнил ответ</span>
            <span v-if="exchange.resolution.is_new" class="badge badge--accent">Новое</span>
          </header>

          <p class="supplement__text">
            {{ exchange.resolution.answer }}
          </p>

          <NuxtLink
            v-if="exchange.resolution.lesson"
            :to="resolutionLink(exchange.resolution)"
            class="supplement__link"
          >
            Открыть урок «{{ exchange.resolution.lesson.lesson_title }}»
          </NuxtLink>
        </div>
      </article>
    </div>

    <form class="composer" @submit.prevent="send">
      <textarea
        ref="field"
        v-model="question"
        class="input composer__field"
        rows="1"
        placeholder="Ваш вопрос по материалам…"
        maxlength="1000"
        :disabled="pending"
        @keydown="onKeydown"
      />
      <button type="submit" class="button-primary composer__send" :disabled="!canSend">
        {{ pending ? 'Ищу…' : 'Спросить' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.consultant {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.head {
  margin-bottom: 0.5rem;
}

.pinned {
  position: sticky;
  top: calc(var(--header-height) + 0.5rem);
  z-index: 2;
  display: flex;
  justify-content: flex-end;
  /* Ни высоты, ни событий: полоса только держит кнопку, разговор идёт под ней. */
  height: 0;
  pointer-events: none;
}

/*
 * Негромко: очищают переписку раз в полгода, а видна кнопка постоянно. Пока на
 * неё не навели, она читается как подпись, а не как действие.
 */
.clear {
  pointer-events: auto;
  padding: 0.25rem 0.7rem;
  border: 0;
  border-radius: var(--radius-pill);
  background: color-mix(in srgb, var(--color-bg) 80%, transparent);
  backdrop-filter: blur(6px);
  color: var(--color-text-faint);
  font: inherit;
  font-size: 0.78rem;
  cursor: pointer;
  transition: color 0.15s ease, background-color 0.15s ease;
}

.clear:hover:not(:disabled) {
  background: var(--color-surface-sunken);
  color: var(--color-text);
}

.clear:disabled {
  cursor: not-allowed;
}

.thread {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.exchange {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.asked {
  align-self: flex-end;
  max-width: min(38rem, 85%);
  margin: 0;
  overflow-wrap: anywhere;
  padding: 0.65rem 1rem;
  border-radius: var(--radius-lg);
  background: var(--color-accent);
  color: var(--color-accent-text);
  font-size: 0.94rem;
  white-space: pre-wrap;
}

.answer {
  align-self: flex-start;
  max-width: min(46rem, 95%);
  padding: 1rem 1.15rem;
}

.answer--pending {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  width: min(28rem, 90%);
}

.answer__text {
  margin: 0;
  font-size: 0.94rem;
  line-height: 1.6;
  white-space: pre-wrap;
  /* Длинное слово — адрес, артикул, название файла — иначе распирает карточку
     за край экрана, и вместе с ней всю страницу. */
  overflow-wrap: anywhere;
}

/*
 * A footnote marker, not a word: raised, small, and no wider than its digits.
 * Kept quiet — there is one after almost every sentence, and a lime chip that
 * often would drown the answer it is annotating. The full lime is spent on the
 * source list below instead, where it appears once per source.
 */
.cite {
  display: inline-block;
  min-width: 1.05em;
  margin: 0 0.1em;
  padding: 0 0.25em;
  border-radius: var(--radius-sm);
  background: var(--control-surface-hover);
  color: var(--color-text-muted);
  font-size: 0.68em;
  font-weight: 600;
  line-height: 1.5;
  text-align: center;
  text-decoration: none;
  vertical-align: super;
}

.cite:hover {
  background: var(--color-highlight);
  color: var(--color-highlight-text);
}

.verdict {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.9rem;
  padding-top: 0.8rem;
  border-top: 1px solid var(--color-border);
  font-size: 0.82rem;
  color: var(--color-text-faint);
}

.verdict__label {
  font-weight: 550;
}

/* Мера под палец: «Да» и «Нет» стоят рядом, и промах по ним означает
   отправленную наугад оценку. */
.verdict__button {
  min-width: 3.5rem;
  padding: 0.35rem 0.9rem;
  font-size: 0.82rem;
}

/* Заявка занимает всю ширину карточки: в ней поле, а не одна кнопка. */
.appeal {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  width: 100%;
}

.appeal__lead {
  margin: 0;
  color: var(--color-text-muted);
}

.appeal__note {
  resize: vertical;
  font-size: 0.85rem;
}

.appeal__send {
  align-self: flex-start;
}

/*
 * Дополнение — сообщение, а не часть ответа: у него своя карточка, отодвинутая
 * от ответа так же, как ответ отодвинут от вопроса.
 */
.supplement {
  /* Той же колонкой, что и ответы: дополнение — такое же сообщение от
     консультанта, только написанное человеком. */
  align-self: flex-start;
  max-width: min(46rem, 100%);
  margin-top: 0.6rem;
  padding: 1rem 1.15rem;
  border-color: var(--color-accent);
}

/* Новое дополнение подсвечено: к нему подводят глаза прокруткой, и оно должно
   отличаться от того, что человек уже читал. */
.supplement--new {
  box-shadow: 0 0 0 2px var(--color-accent);
}

.supplement__head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.supplement__title {
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-faint);
}

.supplement__text {
  margin: 0.5rem 0 0;
  line-height: 1.6;
}

.supplement__link {
  display: inline-block;
  margin-top: 0.6rem;
  font-size: 0.85rem;
}

.sources {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-top: 1rem;
  padding-top: 0.85rem;
  border-top: 1px solid var(--color-border);
}

/*
 * Идёт следом за источниками, и вторая черта между ними была бы лишней: два
 * списка под одним ответом читаются как один, разделённый подписями.
 */
.sources--related,
.sources--experts {
  margin-top: 0.35rem;
  padding-top: 0;
  border-top: none;
}

.sources__label {
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-faint);
}

.source {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.4rem 0.5rem;
  border-radius: var(--radius-sm);
  color: inherit;
  text-decoration: none;
  transition: background-color 0.15s ease;
}

.source:hover {
  background: var(--control-surface-hover);
}

.source__index {
  flex-shrink: 0;
  width: 1.35rem;
  height: 1.35rem;
  border-radius: var(--radius-pill);
  background: var(--color-highlight);
  color: var(--color-highlight-text);
  font-size: 0.72rem;
  font-weight: 600;
  line-height: 1.35rem;
  text-align: center;
}

.source__body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  font-size: 0.85rem;
}

.source__lesson {
  font-weight: 550;
}

.source__question {
  margin-top: 0.2rem;
  font-weight: 500;
}

.source__quote {
  display: block;
  margin-top: 0.3rem;
  padding-left: 0.6rem;
  border-left: 2px solid var(--color-border-strong);
  color: var(--color-text-muted);
  font-size: 0.85rem;
  line-height: 1.5;
  quotes: none;
  overflow-wrap: anywhere;
}

/*
 * Stays within reach as the conversation grows: the page scrolls under it and
 * it stops just above the bottom edge. The backdrop is opaque so answers do not
 * show through the field as they pass beneath it.
 */
.composer {
  position: sticky;
  bottom: 1rem;
  z-index: 1;
  display: flex;
  align-items: flex-end;
  gap: 0.6rem;
  padding: 0.6rem;
  border-radius: var(--radius-lg);
  background: var(--color-bg);
  box-shadow: 0 0 0 1px var(--color-border), var(--shadow-md);
}

.composer__field {
  flex: 1;
  min-width: 0;
  min-height: 2.75rem;
  max-height: 10rem;
  /* Высоту ведёт содержимое: ручку растягивания на телефоне не ухватить. */
  resize: none;
  overflow-y: auto;
  font: inherit;
  font-size: 0.94rem;
  line-height: 1.5;
}

.composer__send {
  flex-shrink: 0;
}

/*
 * На телефоне.
 *
 * Разговор занимает всю ширину: пузыри в 85 % на узком экране оставляют поля,
 * которые ничего не разделяют, зато отнимают строку у текста.
 */
@media (max-width: 40rem) {
  .thread {
    gap: 1.1rem;
  }

  .asked {
    max-width: 92%;
    padding: 0.55rem 0.85rem;
  }

  .answer {
    max-width: 100%;
    padding: 0.85rem 0.9rem;
  }

  .composer {
    bottom: 0.5rem;
    padding: 0.5rem;
    gap: 0.45rem;
  }

  .composer__send {
    padding-inline: 0.9rem;
  }

  .source__quote {
    font-size: 0.82rem;
  }
}

.skeleton-line {
  width: 100%;
  height: 1rem;
}

.skeleton-line--short {
  width: 60%;
}

@media (prefers-reduced-motion: reduce) {
  .source { transition: none; }
}
</style>