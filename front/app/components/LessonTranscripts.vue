<script setup lang="ts">
import type { JSONContent } from '@tiptap/core'
import type {
  AnswerSourceKind,
  LessonAttachment,
  LessonSummary,
  LessonTranscript,
} from '~/types/lms'
import { blockOutline } from '~/utils/editor/blockOutline'
import { pluralise } from '~/utils/plural'

const props = defineProps<{
  lessonId: string
  lesson: LessonSummary
  attachments: LessonAttachment[]
  /** Документ статьи как он сейчас в редакторе — по нему собирается список блоков. */
  document: JSONContent | null
  isSuggesting: boolean
}>()

const emit = defineEmits<{
  suggest: [transcriptId: number]
}>()

const { fetchTranscripts, saveTranscript, deleteTranscript } = useLmsApi()

/**
 * Единица содержания урока: то, к чему расшифровка может быть привязана.
 *
 * Ключ собирается из вида и адреса, потому что сравнивать нечего: у видео адрес
 * пуст, у файла это номер, у блока — строка.
 */
interface Unit {
  key: string
  kind: AnswerSourceKind
  attachmentId: number | null
  blockId: string | null
  title: string
  hint: string
}

const transcripts = ref<LessonTranscript[]>([])
const isLoading = ref(true)
const error = ref<string | null>(null)

/** Какая единица раскрыта на чтение, какая — на правку, и что в её поле. */
const expanded = ref<string | null>(null)
const editing = ref<string | null>(null)
const draft = ref('')
const pending = ref<string | null>(null)

const hasVideo = computed(() => Boolean(props.lesson.video_url || props.lesson.video_upload_url))
const hasArticle = computed(() => blockOutline(props.document).length > 0)

const units = computed<Unit[]>(() => {
  const list: Unit[] = []

  if (hasVideo.value) {
    list.push({
      key: 'video',
      kind: 'video',
      attachmentId: null,
      blockId: null,
      title: 'Видео урока',
      hint: 'Расшифровка записи — .srt, .vtt или текст с таймкодами',
    })
  }

  for (const file of props.attachments) {
    list.push({
      key: `attachment:${file.id}`,
      kind: 'attachment',
      attachmentId: file.id,
      blockId: null,
      title: file.name,
      hint: 'Текст документа. Метки вида «Страница 4» станут ссылками на лист',
    })
  }

  // Одна на весь текст, а не на абзац: у статьи на семьдесят абзацев список
  // превращался в семьдесят одинаковых строк «текст урока», между которыми
  // нечего выбирать. Место внутри статьи помнит кусок расшифровки.
  if (hasArticle.value) {
    list.push({
      key: 'text',
      kind: 'text',
      attachmentId: null,
      blockId: null,
      title: 'Текст урока',
      hint: 'Берётся из самой статьи. Исправьте, если словами в ней сказано не всё',
    })
  }

  return list
})

function transcriptFor(unit: Unit): LessonTranscript | undefined {
  return transcripts.value.find(one =>
    one.source_kind === unit.kind
    && one.source_attachment_id === unit.attachmentId
    && one.source_block_id === unit.blockId,
  )
}

/**
 * Объём расшифровки в знаках, а не в байтах: в базе она лежит текстом, и
 * килобайты сказали бы автору о его расшифровке меньше, чем длина.
 */
function size(characters: number): string {
  if (characters < 1000) {
    return `${characters} ${pluralise(characters, 'знак', 'знака', 'знаков')}`
  }

  return `${Math.round(characters / 1000)} тыс. знаков`
}

async function load() {
  isLoading.value = true

  try {
    const { data } = await fetchTranscripts(props.lessonId)
    transcripts.value = data
  }
  catch {
    error.value = 'Не удалось загрузить список расшифровок.'
  }
  finally {
    isLoading.value = false
  }
}

function toggleExpanded(unit: Unit) {
  expanded.value = expanded.value === unit.key ? null : unit.key
}

/**
 * Открывает расшифровку на правку с её нынешним текстом.
 *
 * Именно с текстом, а не с пустым полем: поправить одну реплику в часовой
 * записи, вставляя её заново, — не правка, а переделка.
 */
function edit(unit: Unit) {
  if (editing.value === unit.key) {
    editing.value = null

    return
  }

  editing.value = unit.key
  expanded.value = null
  draft.value = transcriptFor(unit)?.content ?? ''
}

async function save(unit: Unit, file: File | null) {
  pending.value = unit.key
  error.value = null

  try {
    await saveTranscript(props.lessonId, {
      source_kind: unit.kind,
      source_attachment_id: unit.attachmentId,
      source_block_id: unit.blockId,
      file,
      text: file ? null : draft.value,
    })

    editing.value = null
    draft.value = ''
    await load()
  }
  catch {
    error.value = 'Не удалось сохранить расшифровку. Проверьте, что это текстовый файл.'
  }
  finally {
    pending.value = null
  }
}

function onFile(unit: Unit, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (file) {
    save(unit, file)
  }

  // Иначе повторный выбор того же файла не поднимет событие, и загрузка после
  // неудачи молча не произойдёт.
  input.value = ''
}

async function remove(transcript: LessonTranscript) {
  pending.value = String(transcript.id)

  try {
    await deleteTranscript(transcript.id)
    await load()
  }
  catch {
    error.value = 'Не удалось удалить расшифровку.'
  }
  finally {
    pending.value = null
  }
}

defineExpose({ reload: load })

onMounted(load)
</script>

<template>
  <section class="transcripts">
    <header class="transcripts__header">
      <h2>Расшифровки</h2>
      <p class="muted">
        Текст того, что урок содержит: сказанного в записи, написанного в приложенных
        документах, набранного в статье. Сотрудник их не видит — по ним ищет консультант
        и из них же берёт вопросы для таблицы.
      </p>
    </header>

    <p v-if="error" class="auth-alert" role="alert">
      {{ error }}
    </p>

    <p v-if="isLoading" class="muted">
      Загружаем…
    </p>

    <p v-else-if="!units.length" class="muted">
      Расшифровывать пока нечего: добавьте видео, файлы или текст — и сохраните урок.
    </p>

    <ul v-else class="units">
      <li v-for="unit in units" :key="unit.key" class="unit">
        <div class="unit__row">
          <span class="unit__title">{{ unit.title }}</span>

          <template v-if="transcriptFor(unit)">
            <span class="badge">
              {{ transcriptFor(unit)!.is_derived ? 'из текста блока' : transcriptFor(unit)!.format }}
            </span>
            <span class="unit__size">{{ size(transcriptFor(unit)!.characters) }}</span>
          </template>
          <span v-else class="unit__size unit__size--empty">нет расшифровки</span>

          <div class="unit__actions">
            <button
              v-if="transcriptFor(unit)"
              type="button"
              class="button-secondary button-sm"
              @click="toggleExpanded(unit)"
            >
              {{ expanded === unit.key ? 'Свернуть' : 'Показать' }}
            </button>

            <button type="button" class="button-secondary button-sm" @click="edit(unit)">
              {{ transcriptFor(unit) ? 'Править' : 'Добавить' }}
            </button>

            <!-- Вопросы у этой расшифровки: источник в них проставится сам —
                 он известен из того, к чему расшифровка привязана. -->
            <button
              v-if="transcriptFor(unit)"
              type="button"
              class="button-secondary button-sm"
              :disabled="isSuggesting"
              @click="emit('suggest', transcriptFor(unit)!.id)"
            >
              Вопросы
            </button>

            <!-- Выведенную удалять нечего: она вернётся при следующем
                 сохранении урока, потому что она и есть его текст. -->
            <button
              v-if="transcriptFor(unit) && !transcriptFor(unit)!.is_derived"
              type="button"
              class="button-danger button-sm"
              :disabled="pending === String(transcriptFor(unit)!.id)"
              @click="remove(transcriptFor(unit)!)"
            >
              Удалить
            </button>
          </div>
        </div>

        <pre v-if="expanded === unit.key" class="unit__full">{{ transcriptFor(unit)!.content }}</pre>

        <div v-if="editing === unit.key" class="editor">
          <p class="muted">
            {{ unit.hint }}
          </p>

          <label class="editor__file">
            <input type="file" accept=".txt,.md,.srt,.vtt" @change="onFile(unit, $event)">
            <span class="button-secondary button-sm">Загрузить файлом</span>
          </label>

          <span class="editor__or">или правьте текст здесь</span>

          <textarea
            v-model="draft"
            class="textarea editor__text"
            rows="14"
            spellcheck="false"
            placeholder="00:12:35 Второй слой сохнет не менее четырёх часов…"
          />

          <div class="editor__actions">
            <button
              type="button"
              class="button-primary button-sm"
              :disabled="!draft.trim() || pending === unit.key"
              @click="save(unit, null)"
            >
              {{ pending === unit.key ? 'Сохраняем…' : 'Сохранить' }}
            </button>
            <button type="button" class="button-ghost button-sm" @click="editing = null">
              Отмена
            </button>
          </div>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
/* Раздел — карточка, как видео и файлы. */
.transcripts {
  margin-top: 1.25rem;
  padding: 1.35rem 1.5rem 1.5rem;
  border-radius: var(--radius-lg);
  background: var(--color-surface);
}

.transcripts__header h2 {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 550;
}

.muted {
  margin: 0.45rem 0 1rem;
  color: var(--color-text-muted);
  font-size: 0.82rem;
  line-height: 1.5;
  max-width: 62ch;
}

.units {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.unit {
  padding: 0.75rem 1rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}

.unit__row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.unit__title {
  flex: 1;
  min-width: 8rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.unit__size {
  color: var(--color-text-muted);
  font-size: 0.8rem;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.unit__size--empty {
  opacity: 0.7;
}

.unit__actions {
  display: flex;
  gap: 0.35rem;
  margin-left: auto;
}

/*
 * Расшифровка целиком.
 *
 * Моноширинным и с сохранением переводов строк: у субтитров структура — часть
 * смысла, и свёрнутая в абзац расшифровка нечитаема. Прокрутка своя, чтобы
 * часовая запись не растянула страницу на сотню экранов.
 */
.unit__full {
  margin: 0.75rem 0 0;
  padding: 0.85rem 1rem;
  max-height: 24rem;
  overflow: auto;
  border-radius: var(--radius);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.82rem;
  line-height: 1.55;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  tab-size: 2;
}

.editor {
  margin-top: 0.85rem;
  padding-top: 0.85rem;
  border-top: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.editor__file input {
  position: absolute;
  width: 1px;
  height: 1px;
  opacity: 0;
  pointer-events: none;
}

.editor__file {
  align-self: flex-start;
  cursor: pointer;
}

.editor__or {
  color: var(--color-text-muted);
  font-size: 0.8rem;
}

/* Тем же моноширинным, что и просмотр: таймкоды выстраиваются в столбец. */
.editor__text {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.85rem;
  line-height: 1.55;
  min-height: 12rem;
}

.editor__actions {
  display: flex;
  gap: 0.75rem;
}
</style>
