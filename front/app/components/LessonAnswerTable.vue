<script setup lang="ts">
import type { JSONContent } from '@tiptap/core'
import type { SelectOption } from '~/components/ui/Select.vue'
import type { ValidationErrors } from '~/composables/useAuth'
import type {
  AnswerSourceKind,
  LessonAnswer,
  LessonAnswerPayload,
  LessonAttachment,
  LessonSummary,
  SuggestedAnswer,
} from '~/types/lms'
import { blockOutline } from '~/utils/editor/blockOutline'
import { pluralise } from '~/utils/plural'
import { parseTimecode, toTimecode } from '~/utils/timecode'

const props = defineProps<{
  lesson: LessonSummary
  answers: LessonAnswer[]
  attachments: LessonAttachment[]
  /** Документ статьи как он сейчас в редакторе — по нему собирается список мест. */
  document: JSONContent | null
  errors: ValidationErrors
  isSubmitting: boolean
  isSuggesting: boolean
}>()

const emit = defineEmits<{
  save: [rows: LessonAnswerPayload[]]
  suggest: []
}>()

/**
 * Черновик строки живёт отдельно от сохранённой.
 *
 * Таймкод человек вводит как «12:35», а сервер принимает секунды: держать в
 * форме то и другое — значит расходиться при каждой правке, поэтому в черновике
 * лежит строка, а число получается при отправке.
 */
interface DraftRow {
  question: string
  answer: string
  source_kind: AnswerSourceKind
  source_attachment_id: number | null
  timecode: string
  source_page: number | null
  source_block_id: string | null
  /** Указывает ли строка ещё на существующее место. */
  isLive: boolean
  /** Посчитаны ли векторы; пусто — смыслового поиска нет, и считать нечего. */
  isIndexed?: boolean
}

function blankRow(): DraftRow {
  return {
    question: '',
    answer: '',
    source_kind: 'text',
    source_attachment_id: null,
    timecode: '',
    source_page: null,
    source_block_id: null,
    isLive: true,
  }
}

function draftFrom(answers: LessonAnswer[]): DraftRow[] {
  return answers.map(row => ({
    question: row.question,
    answer: row.answer,
    source_kind: row.source_kind,
    source_attachment_id: row.source_attachment_id,
    timecode: row.source_seconds === null ? '' : toTimecode(row.source_seconds),
    source_page: row.source_page,
    source_block_id: row.source_block_id,
    isLive: row.source_is_live,
    isIndexed: row.is_indexed,
  }))
}

const rows = ref<DraftRow[]>(draftFrom(props.answers))

/**
 * Чем сохранённая таблица отличается от прежней.
 *
 * Страница перечитывает урок после каждого действия — сохранения урока,
 * загрузки файла, смены видео, — и каждый раз приносит новый массив с тем же
 * содержимым. Сравнение по ссылке принимало это за изменение и стирало всё,
 * что автор набрал и ещё не сохранил: написал три вопроса, приложил файл — и
 * они пропали.
 */
function signatureOf(answers: LessonAnswer[]): string {
  return JSON.stringify(answers.map(row => [
    row.question,
    row.answer,
    row.source_kind,
    row.source_attachment_id,
    row.source_seconds,
    row.source_page,
    row.source_block_id,
  ]))
}

let loaded = signatureOf(props.answers)

watch(() => props.answers, (answers) => {
  const signature = signatureOf(answers)

  if (signature === loaded) {
    return
  }

  loaded = signature
  rows.value = draftFrom(answers)
})

const hasVideo = computed(() => Boolean(props.lesson.video_url || props.lesson.video_upload_url))

/** Виды источника, которых у урока нет, предлагать незачем. */
const sourceKinds = computed<SelectOption<AnswerSourceKind>[]>(() => {
  const options: SelectOption<AnswerSourceKind>[] = [{ value: 'text', label: 'Текст урока' }]

  if (hasVideo.value) {
    options.push({ value: 'video', label: 'Видео урока' })
  }

  if (props.attachments.length) {
    options.push({ value: 'attachment', label: 'Приложенный файл' })
  }

  return options
})

const attachmentOptions = computed<SelectOption<number | null>[]>(() => [
  { value: null, label: 'Выберите файл…' },
  ...props.attachments.map(file => ({ value: file.id, label: file.name })),
])

/**
 * Места в тексте, названные первыми словами.
 *
 * Идентификатор человеку ничего не говорит, поэтому в списке — начало абзаца.
 * Собирается из документа в редакторе, а не из сохранённого урока: автор
 * дописал абзац и тут же хочет на него сослаться.
 */
const blockOptions = computed<SelectOption<string | null>[]>(() => [
  { value: null, label: 'Весь текст урока' },
  ...blockOutline(props.document).map(block => ({ value: block.id, label: block.preview })),
])

/**
 * Какие строки раскрыты.
 *
 * Свёрнутая показывает вопрос — по нему строку и узнают. Ответ с источником
 * занимают вчетверо больше места, и развёрнутые все разом они превращают
 * колонку в простыню, по которой не пролистать до нужного.
 *
 * Ключ — сама строка, а не её номер: удалили третью, и по номерам раскрытой
 * оказалась бы уже четвёртая.
 */
const opened = ref<Set<DraftRow>>(new Set())

const allOpen = computed(() => rows.value.length > 0 && opened.value.size === rows.value.length)

function toggleRow(row: DraftRow) {
  const next = new Set(opened.value)
  next.has(row) ? next.delete(row) : next.add(row)
  opened.value = next
}

function toggleAll() {
  opened.value = allOpen.value ? new Set() : new Set(rows.value)
}

function addRow() {
  const row = blankRow()

  rows.value.push(row)

  // Новая — сразу раскрытой: её затем и добавили, чтобы заполнить.
  opened.value = new Set([...opened.value, row])
}

function removeRow(index: number) {
  rows.value.splice(index, 1)
}

/** Смена вида источника уносит с собой место от прошлого выбора. */
function onKindChange(row: DraftRow) {
  row.source_attachment_id = null
  row.timecode = ''
  row.source_page = null
  row.source_block_id = null
  row.isLive = true
}

function errorFor(index: number, field: string): string | undefined {
  return props.errors[`answers.${index}.${field}`]?.[0]
}

/** Докуда полю разрешено вырасти, прежде чем оно начнёт прокручиваться. */
const ANSWER_MAX_HEIGHT = 220

/**
 * Поле ответа по высоте содержимого.
 *
 * Постоянная высота в узкой колонке никуда не годится: у коротких ответов
 * половина поля пустует, у длинных текст обрывается посреди строки — и обрыв
 * этот выглядит поломкой, а не прокруткой. Растёт до предела, дальше
 * прокручивается.
 */
function fit(field: HTMLTextAreaElement) {
  field.style.height = 'auto'
  field.style.height = `${Math.min(field.scrollHeight, ANSWER_MAX_HEIGHT)}px`
}

const vAutosize = {
  mounted: (el: HTMLTextAreaElement) => {
    fit(el)
    el.addEventListener('input', () => fit(el))

    // И ещё раз, когда доедет шрифт. Первый замер идёт подстановочным, метрики
    // у него другие, и высота выходит меньше нужной: поле обрывает текст
    // посередине строки, а обрыв этот выглядит поломкой, а не прокруткой.
    window.document.fonts?.ready.then(() => fit(el))
  },
  // Строки приходят и от модели, и с сервера после сохранения — тогда высоту
  // надо пересчитать, хотя ввода не было.
  updated: (el: HTMLTextAreaElement) => fit(el),
}

/**
 * Введён ли таймкод строки разборчиво.
 *
 * Пустой — разборчив: это ссылка на запись с начала, и она осмысленна. Требовать
 * секунду было ошибкой: вопрос, предложенный по расшифровке без таймкодов,
 * приходит с пустым временем — и запрет молча отключал сохранение всей таблицы.
 */
function timecodeIsUsable(row: DraftRow): boolean {
  return row.source_kind !== 'video'
    || row.timecode.trim() === ''
    || parseTimecode(row.timecode) !== null
}

/** Номера строк, чей таймкод разобрать не удалось. */
const brokenTimecodes = computed(() =>
  rows.value.flatMap((row, index) => (timecodeIsUsable(row) ? [] : [index + 1])),
)

/**
 * Ошибки, которым не нашлось поля.
 *
 * Такие приходят на саму таблицу, а не на строку, и без этого пропадали
 * бесследно: сервер отказывал, а автор видел лишь то, что ничего не произошло.
 */
const generalErrors = computed(() =>
  Object.entries(props.errors)
    .filter(([field]) => !field.startsWith('answers.'))
    .flatMap(([, messages]) => messages),
)

function submit() {
  emit('save', rows.value.map(row => ({
    question: row.question,
    answer: row.answer,
    source_kind: row.source_kind,
    source_attachment_id: row.source_kind === 'attachment' ? row.source_attachment_id : null,
    source_seconds: row.source_kind === 'video' ? parseTimecode(row.timecode) : null,
    source_page: row.source_kind === 'attachment' ? row.source_page : null,
    source_block_id: row.source_kind === 'text' ? row.source_block_id : null,
  })))
}

/* ---------- черновик от модели ---------- */

const suggestions = ref<SuggestedAnswer[] | null>(null)
const picked = ref<Set<number>>(new Set())

/** Показывает предложения модели. Вызывается страницей, когда те приехали. */
function showSuggestions(drafts: SuggestedAnswer[]) {
  suggestions.value = drafts
  picked.value = new Set(drafts.map((_, index) => index))
}

function togglePick(index: number) {
  const next = new Set(picked.value)
  next.has(index) ? next.delete(index) : next.add(index)
  picked.value = next
}

function acceptPicked() {
  for (const [index, draft] of (suggestions.value ?? []).entries()) {
    if (!picked.value.has(index)) {
      continue
    }

    // Источник приходит готовым: он выведен из расшифровки, откуда взят
    // вопрос, — автору не остаётся ничего указывать руками.
    rows.value.push({
      ...blankRow(),
      question: draft.question,
      answer: draft.answer,
      source_kind: draft.source_kind,
      source_attachment_id: draft.source_attachment_id,
      timecode: draft.source_seconds === null ? '' : toTimecode(draft.source_seconds),
      source_page: draft.source_page,
      source_block_id: draft.source_block_id,
    })
  }

  suggestions.value = null
}

defineExpose({ showSuggestions })
</script>

<template>
  <section id="lesson-answers" class="answers">
    <header class="answers__header">
      <div>
        <h2>Вопросы урока</h2>
        <p class="muted">
          На какие вопросы отвечает этот урок и где именно написан ответ.
          По этой таблице консультант ищет прежде всего — а урок без неё
          остаётся виден только через свой текст.
          Чтобы сослаться на новый абзац, сперва сохраните урок.
        </p>
      </div>

      <button
        type="button"
        class="button-secondary"
        :disabled="isSuggesting"
        @click="emit('suggest')"
      >
        {{ isSuggesting ? 'Читаем урок…' : 'Предложить вопросы' }}
      </button>
    </header>

    <!-- Черновик модели: ничего не сохранено, пока автор не утвердит. -->
    <div v-if="suggestions" class="drafts">
      <p v-if="!suggestions.length" class="muted">
        Модель не нашла в тексте урока вопросов с готовыми ответами.
      </p>

      <template v-else>
        <p class="muted">
          Вопросы взяты из расшифровок, поэтому источник у каждого уже проставлен —
          вплоть до секунды в записи и страницы в файле.
        </p>

        <label v-for="(draft, index) in suggestions" :key="index" class="draft">
          <input type="checkbox" :checked="picked.has(index)" @change="togglePick(index)">
          <span class="draft__body">
            <span class="draft__question">{{ draft.question }}</span>
            <span class="draft__answer">{{ draft.answer }}</span>
          </span>
        </label>

        <div class="actions">
          <button type="button" class="button-primary" @click="acceptPicked">
            Добавить отмеченные ({{ picked.size }})
          </button>
          <button type="button" class="button-ghost" @click="suggestions = null">
            Отмена
          </button>
        </div>
      </template>
    </div>

    <p v-for="message in generalErrors" :key="message" class="auth-alert" role="alert">
      {{ message }}
    </p>

    <p v-if="!rows.length" class="muted empty">
      Пока ни одного вопроса. Добавьте первый — или попросите модель предложить черновик.
    </p>

    <div v-else class="toolbar">
      <span class="muted toolbar__count">{{ rows.length }} {{ pluralise(rows.length, 'вопрос', 'вопроса', 'вопросов') }}</span>
      <button type="button" class="button-ghost button-sm" @click="toggleAll">
        {{ allOpen ? 'Свернуть все' : 'Раскрыть все' }}
      </button>
    </div>

    <ol class="rows">
      <li v-for="(row, index) in rows" :key="index" class="row" :class="{ 'row--open': opened.has(row) }">
        <div class="row__head">
          <button
            type="button"
            class="row__toggle"
            :aria-expanded="opened.has(row)"
            @click="toggleRow(row)"
          >
            <span class="row__number">{{ index + 1 }}</span>
            <span class="row__title">{{ row.question || 'Новый вопрос' }}</span>
            <span class="row__chevron" aria-hidden="true">{{ opened.has(row) ? '▾' : '▸' }}</span>
          </button>

          <span v-if="!row.isLive" class="badge badge--warn" title="Место, на которое ссылается строка, больше не существует">
            !
          </span>
          <!-- Только когда смысловой поиск настроен: без него векторов не
               будет никогда, и «ещё считаем» — вечное обещание. -->
          <span
            v-else-if="row.isIndexed === false"
            class="badge"
            title="Смысловые векторы ещё считаются. Пока их нет, строку находит только поиск по словам — обычно это занимает несколько секунд"
          >
            ⋯
          </span>

          <button type="button" class="row__remove" title="Удалить строку" @click="removeRow(index)">
            ×
          </button>
        </div>

        <template v-if="opened.has(row)">
          <div class="field">
            <label :for="`question-${index}`">Вопрос</label>
            <input
              :id="`question-${index}`"
              v-model.trim="row.question"
              class="input"
              type="text"
              placeholder="Сколько сохнет второй слой?"
            >
            <p v-if="errorFor(index, 'question')" class="field__error">
              {{ errorFor(index, 'question') }}
            </p>
          </div>

        <div class="field">
          <label :for="`answer-${index}`">Ответ</label>
          <textarea
            :id="`answer-${index}`"
            v-model.trim="row.answer"
            v-autosize
            class="textarea"
            rows="2"
            placeholder="Не менее 4 часов при 20 °C."
          />
          <p v-if="errorFor(index, 'answer')" class="field__error">
            {{ errorFor(index, 'answer') }}
          </p>
        </div>

        <div class="row__source">
          <div class="field field--kind">
            <label>Источник</label>
            <UiSelect
              v-model="row.source_kind"
              :options="sourceKinds"
              @update:model-value="onKindChange(row)"
            />
            <p v-if="errorFor(index, 'source_kind')" class="field__error">
              {{ errorFor(index, 'source_kind') }}
            </p>
          </div>

          <div v-if="row.source_kind === 'text'" class="field">
            <label>Место в тексте</label>
            <UiSelect v-model="row.source_block_id" :options="blockOptions" />
            <p v-if="errorFor(index, 'source_block_id')" class="field__error">
              {{ errorFor(index, 'source_block_id') }}
            </p>
          </div>

          <div v-else-if="row.source_kind === 'video'" class="field field--narrow">
            <label :for="`timecode-${index}`">Таймкод</label>
            <input
              :id="`timecode-${index}`"
              v-model.trim="row.timecode"
              class="input"
              type="text"
              inputmode="numeric"
              placeholder="12:35"
            >
            <p v-if="row.timecode && parseTimecode(row.timecode) === null" class="field__error">
              Время в виде мм:сс или ч:мм:сс.
            </p>
          </div>

          <template v-else>
            <div class="field">
              <label>Файл</label>
              <UiSelect v-model="row.source_attachment_id" :options="attachmentOptions" />
              <p v-if="errorFor(index, 'source_attachment_id')" class="field__error">
                {{ errorFor(index, 'source_attachment_id') }}
              </p>
            </div>

            <div class="field field--narrow">
              <label :for="`page-${index}`">Страница</label>
              <input :id="`page-${index}`" v-model.number="row.source_page" class="input" type="number" min="1" max="10000">
            </div>
            </template>
          </div>
        </template>
      </li>
    </ol>

    <div class="actions">
      <button type="button" class="button-secondary" @click="addRow">
        + Добавить вопрос
      </button>

      <button
        type="button"
        class="button-primary"
        :disabled="isSubmitting || brokenTimecodes.length > 0"
        @click="submit"
      >
        {{ isSubmitting ? 'Сохраняем…' : 'Сохранить таблицу' }}
      </button>

      <!-- Отключённая кнопка обязана объяснить себя: молча неработающая
           кнопка неотличима от сломанной страницы. -->
      <span v-if="brokenTimecodes.length" class="field__error">
        Проверьте время в {{ brokenTimecodes.length === 1 ? 'строке' : 'строках' }}
        {{ brokenTimecodes.join(', ') }} — оно пишется как мм:сс.
      </span>
    </div>
  </section>
</template>

<style scoped>
/*
 * Таблица возглавляет свою колонку: отбивка сверху и черта под чужим
 * содержимым ей больше не нужны, а ширину задаёт колонка. Карточка — как у
 * разделов слева: иначе она единственная на странице лежала бы прямо на фоне.
 */
.answers {
  min-width: 0;
  padding: 1.35rem 1.5rem 1.5rem;
  border-radius: var(--radius-lg);
  background: var(--color-surface);
}

.answers__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.answers__header h2 {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 550;
}

.muted {
  margin: 0.25rem 0 1rem;
  color: var(--color-text-muted);
  font-size: 0.82rem;
}

.empty {
  margin: 1.5rem 0;
}

.drafts {
  margin: 1rem 0 1.5rem;
  padding: 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}

.draft {
  display: flex;
  gap: 0.65rem;
  align-items: flex-start;
  padding: 0.6rem 0;
  cursor: pointer;
}

.draft + .draft {
  border-top: 1px solid var(--color-border);
}

.draft__body {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.draft__question {
  font-weight: 500;
}

.draft__answer {
  color: var(--color-text-muted);
  font-size: 0.88rem;
}

.rows {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.row {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.7rem 0.8rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}

.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.6rem;
}

.toolbar__count {
  margin: 0;
}

.row__head {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

/*
 * Заголовок строки — он же переключатель.
 *
 * Кнопкой, а не значком рядом: попасть в стрелку на телефоне трудно, а вопрос
 * — самая крупная цель в строке и одновременно то, по чему строку узнают.
 */
.row__toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex: 1;
  min-width: 0;
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.row__number {
  color: var(--color-text-muted);
  font-size: 0.85rem;
  font-variant-numeric: tabular-nums;
  flex-shrink: 0;
}

/* В одну строку: свёрнутый вид на то и свёрнутый. */
.row__title {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.9rem;
}

.row--open .row__title {
  color: var(--color-text-muted);
}

.row__chevron {
  flex-shrink: 0;
  color: var(--color-text-muted);
  font-size: 0.75rem;
}

.row__remove {
  flex-shrink: 0;
  border: 0;
  background: none;
  color: var(--color-text-muted);
  font-size: 1.3rem;
  line-height: 1;
  cursor: pointer;
}

.row__remove:hover {
  color: var(--color-text);
}

/*
 * Значком, а не словами: в свёрнутой строке место занято вопросом, и подпись
 * «Источник потерян» вытеснила бы его. Что она значит, говорит подсказка.
 */
.badge {
  flex-shrink: 0;
  display: inline-grid;
  place-items: center;
  width: 1.35rem;
  height: 1.35rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--color-text) 8%, transparent);
  color: var(--color-text-muted);
  font-size: 0.75rem;
  cursor: help;
}

.badge--warn {
  background: color-mix(in srgb, #f59e0b 22%, transparent);
  color: var(--color-text);
}

.row__source {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  flex: 1;
  min-width: 8.5rem;
}

/* Подпись поля мельче самого поля: в узкой колонке её задача — назвать, а не
   спорить за внимание с содержимым. */
.field label {
  font-size: 0.78rem;
  color: var(--color-text-muted);
}

.field--narrow {
  flex: 0 0 6.5rem;
  min-width: 0;
}

.field--kind {
  flex: 1 1 9rem;
}

/* Переносится: колонка узкая, и две кнопки в строке в неё не всегда влезают —
   без переноса вторая уезжала за край карточки. */
.actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.6rem;
  margin-top: 1.25rem;
}
</style>
