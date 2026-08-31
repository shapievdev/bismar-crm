<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { JSONContent } from '@tiptap/core'
import type { LessonAnswerPayload, LessonPayload, QuizPayload, SuggestedAnswer } from '~/types/lms'
import { type UploadedMedia, withResolvedMedia, withoutResolvedMedia } from '~/utils/editor/attachments'

definePageMeta({ middleware: 'auth', permission: 'courses.update' })

const route = useRoute()
const {
  fetchLesson,
  updateLesson,
  saveQuiz,
  deleteQuiz,
  uploadAttachment,
  updateAttachment,
  deleteAttachment,
  saveAnswers,
  suggestAnswers,
} = useLmsApi()

const lessonId = computed(() => String(route.params.lesson))
const courseSlug = computed(() => String(route.params.slug))

const { data, error, refresh } = await useAsyncData(
  () => `lms.lesson.edit.${lessonId.value}`,
  () => fetchLesson(lessonId.value),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Урок не найден', fatal: true })
}

const lesson = computed(() => data.value?.data)
useHead(() => ({ title: `Урок — ${lesson.value?.title ?? ''}` }))

const form = ref<LessonPayload>({
  title: lesson.value?.title ?? '',
  content: lesson.value?.content ?? '',
  video_url: lesson.value?.video_url ?? '',
  duration_minutes: lesson.value?.duration_minutes ?? null,
})

// Addresses are resolved on the way in and dropped again on the way out, so
// the record holds attachment ids and never a signature that expires.
const document = ref<JSONContent | null>(
  withResolvedMedia(lesson.value?.content_json ?? null, lesson.value?.attachments ?? []),
)

// A save refetches the lesson, and deleting a file refreshes the attachment
// list — either way the document needs its addresses resolved again.
watch(lesson, (value) => {
  document.value = withResolvedMedia(value?.content_json ?? null, value?.attachments ?? [])
})

/**
 * Media dropped into the article is stored as a normal lesson attachment, so
 * it is listed, replaceable and deleted along with the lesson. The editor gets
 * back both halves: the id to keep, and the address to show right now.
 */
async function uploadInlineImage(file: File, options: UploadOptions): Promise<UploadedMedia> {
  const { data } = await uploadAttachment(lessonId.value, file, 'Изображение в статье', options)

  return { id: data.id, url: data.url }
}

/**
 * An attachment too, not the lesson's own video.
 *
 * A lesson has exactly one video slot, and posting to it replaces whatever was
 * there and deletes the old object — so sending an article's video down that
 * route silently swapped out the lesson's main recording.
 */
async function uploadInlineVideo(file: File, options: UploadOptions): Promise<UploadedMedia> {
  const { data } = await uploadAttachment(lessonId.value, file, 'Видео в статье', options)

  return { id: data.id, url: data.url }
}

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSaving = ref(false)
const savedAt = ref<string | null>(null)

const quizErrors = ref<ValidationErrors>({})
const isSavingQuiz = ref(false)
const showQuizBuilder = ref(Boolean(lesson.value?.quiz))

const answerErrors = ref<ValidationErrors>({})
const isSavingAnswers = ref(false)
const isSuggesting = ref(false)
const answerTable = useTemplateRef<{ showSuggestions: (drafts: SuggestedAnswer[]) => void }>('answerTable')
const transcripts = useTemplateRef<{ reload: () => Promise<void> }>('transcripts')

async function save() {
  isSaving.value = true
  errors.value = {}
  generalError.value = null
  savedAt.value = null

  try {
    await updateLesson(lessonId.value, {
      ...form.value,
      // The server derives the searchable plain text from the document, so
      // only the document itself is sent.
      content: null,
      content_json: withoutResolvedMedia(document.value),
      video_url: form.value.video_url || null,
    })

    await refresh()

    // Правка статьи пересобирает выведенные расшифровки на сервере: у нового
    // абзаца она появляется, у исчезнувшего пропадает. Без этого список
    // показывал бы вчерашнее состояние.
    await transcripts.value?.reload()

    savedAt.value = new Date().toLocaleTimeString('ru-RU')
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить урок.'
    }
  }
  finally {
    isSaving.value = false
  }
}

async function persistQuiz(payload: QuizPayload) {
  isSavingQuiz.value = true
  quizErrors.value = {}

  try {
    await saveQuiz(lessonId.value, payload)
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      quizErrors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить тест.'
    }
  }
  finally {
    isSavingQuiz.value = false
  }
}

async function persistAnswers(rows: LessonAnswerPayload[]) {
  isSavingAnswers.value = true
  answerErrors.value = {}

  try {
    await saveAnswers(lessonId.value, rows)
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      answerErrors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить таблицу вопросов.'
    }
  }
  finally {
    isSavingAnswers.value = false
  }
}

/**
 * Просит модель прочитать урок и предложить вопросы.
 *
 * Предложения нигде не сохраняются — они уходят прямо в таблицу, где автор
 * отбирает нужные. Отказ модели ничему не мешает: заполнить таблицу руками
 * можно и без подсказки.
 */
async function askForSuggestions(transcriptId?: number) {
  isSuggesting.value = true
  generalError.value = null

  try {
    const { data } = await suggestAnswers(lessonId.value, transcriptId)
    answerTable.value?.showSuggestions(data)

    // Таблица ниже расшифровок, и предложения появляются вне поля зрения
    // автора, который только что нажал кнопку у расшифровки.
    //
    // Через window: `document` здесь — статья в редакторе, а не страница.
    await nextTick()
    window.document.getElementById('lesson-answers')
      ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }
  catch {
    generalError.value = 'Не удалось получить подсказку. Заполните таблицу вручную или попробуйте позже.'
  }
  finally {
    isSuggesting.value = false
  }
}

async function removeQuiz() {
  isSavingQuiz.value = true

  try {
    await deleteQuiz(lessonId.value)
    showQuizBuilder.value = false
    await refresh()
  }
  catch {
    generalError.value = 'Не удалось удалить тест.'
  }
  finally {
    isSavingQuiz.value = false
  }
}
</script>

<template>
  <section v-if="lesson">
    <header class="page-header">
      <h1>{{ lesson.title }}</h1>
      <div class="page-header__links">
        <NuxtLink :to="`/lms/${courseSlug}/lessons/${lessonId}`" class="link">
          Как видит ученик
        </NuxtLink>
        <NuxtLink :to="`/lms/${courseSlug}/edit`" class="link">
          ← К программе
        </NuxtLink>
      </div>
    </header>

    <p v-if="generalError" class="auth-alert" role="alert">
      {{ generalError }}
    </p>

    <!--
      Материал слева, вопросы справа.

      Одно правится по другому: автор смотрит в расшифровку и тут же
      формулирует вопрос. В один столбец таблица оказывалась экраном ниже
      всего, из чего её составляют, и приходилось ходить туда-обратно.
    -->
    <div class="workbench">
      <div class="workbench__material">
        <form class="form" novalidate @submit.prevent="save">
      <FormField id="title" v-model="form.title" label="Название" :errors="errors.title" />

      <div class="row">
        <div class="field">
          <label for="video">Ссылка на видео</label>
          <input id="video" v-model.trim="form.video_url" type="url" placeholder="https://…">
          <p v-if="errors.video_url?.length" class="field__error">
            {{ errors.video_url[0] }}
          </p>
        </div>

        <div class="field field--narrow">
          <label for="duration">Длительность, мин</label>
          <input id="duration" v-model.number="form.duration_minutes" type="number" min="1" max="6000">
          <p v-if="errors.duration_minutes?.length" class="field__error">
            {{ errors.duration_minutes[0] }}
          </p>
        </div>
      </div>

      <div class="field">
        <label class="field-label">Содержание урока</label>
        <ClientOnly>
          <EditorRichTextEditor
            v-model="document"
            :upload-image="uploadInlineImage"
            :upload-video="uploadInlineVideo"
            placeholder="Заголовки, текст, цитаты, таблицы, изображения, видео и блоки HTML…"
          />

          <template #fallback>
            <div class="editor-skeleton skeleton" />
          </template>
        </ClientOnly>
        <p v-if="errors.content_json?.length" class="field__error">
          {{ errors.content_json[0] }}
        </p>
      </div>

          <div class="actions">
            <button type="submit" class="button-primary" :disabled="isSaving">
              {{ isSaving ? 'Сохраняем…' : 'Сохранить урок' }}
            </button>
            <span v-if="savedAt" class="muted">Сохранено в {{ savedAt }}</span>
          </div>
        </form>

        <LessonVideoManager
          :lesson-id="lessonId"
          :lesson="lesson"
          @changed="refresh"
        />

        <AttachmentManager
          :attachments="lesson.attachments ?? []"
          :upload-file="(file, description, options) => uploadAttachment(lessonId, file, description, options)"
          :rename-file="updateAttachment"
          :remove-file="deleteAttachment"
          @changed="refresh"
        />

        <!-- После вложений и видео: расшифровка привязана к ним, и до появления
             файлов расшифровывать нечего. -->
        <LessonTranscripts
          ref="transcripts"
          :lesson-id="lessonId"
          :lesson="lesson"
          :attachments="lesson.attachments ?? []"
          :document="document"
          :is-suggesting="isSuggesting"
          @suggest="askForSuggestions"
        />
      </div>

      <!--
        Липко к экрану: автор прокручивает расшифровку слева и переносит из неё
        вопросы, а таблица при этом должна оставаться на виду.
      -->
      <aside class="workbench__answers">
        <LessonAnswerTable
          ref="answerTable"
          :lesson="lesson"
          :answers="lesson.answers ?? []"
          :attachments="lesson.attachments ?? []"
          :document="document"
          :errors="answerErrors"
          :is-submitting="isSavingAnswers"
          :is-suggesting="isSuggesting"
          @save="persistAnswers"
          @suggest="askForSuggestions"
        />
      </aside>
    </div>

    <QuizBuilder
      v-if="showQuizBuilder"
      :quiz="lesson.quiz ?? null"
      :errors="quizErrors"
      :is-submitting="isSavingQuiz"
      :fixed-passing-score="100"
      @save="persistQuiz"
      @remove="removeQuiz"
    />

    <!-- Разбор — только у сохранённого теста: пока его нет, считать нечего.
         Ключ здесь и так открыт: автор видит верные ответы в самом тесте. -->
    <QuizStatisticsPanel v-if="lesson.quiz" :key="lesson.quiz.id" :lesson-id="lessonId" />

    <section v-else class="add-quiz">
      <button type="button" class="button-plain" @click="showQuizBuilder = true">
        Добавить тест к уроку
      </button>
    </section>
  </section>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.25rem;
  flex-wrap: wrap;
}

.page-header h1 {
  margin: 0;
  font-size: 1.5rem;
}

.page-header__links {
  display: flex;
  gap: 1rem;
}

.link {
  font-size: 0.9rem;
  text-decoration: none;
}

/*
 * Две колонки: материал и вопросы к нему.
 *
 * Правая уже левой и не тянется: статья, видео и расшифровки — то, что читают,
 * им нужна ширина строки. Таблица вопросов — то, что заполняют, ей нужна
 * досягаемость.
 */
.workbench {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 30rem);
  align-items: start;
  gap: 2rem;
}

.workbench__material {
  min-width: 0;
}

.workbench__answers {
  position: sticky;
  /* Под липкой шапкой, а не под ней. */
  top: calc(var(--header-height) + 1rem);
  min-width: 0;
  max-height: calc(100vh - var(--header-height) - 2rem);
  overflow-y: auto;
  /* Полосе прокрутки нужен зазор, иначе она ложится на поля. */
  padding-right: 0.35rem;
}

/*
 * На узком экране колонки складываются: рядом им нужно около сотни знаков
 * ширины на двоих, а меньшего не хватит ни одной.
 */
@media (max-width: 78rem) {
  .workbench {
    grid-template-columns: minmax(0, 1fr);
  }

  .workbench__answers {
    position: static;
    max-height: none;
    overflow: visible;
    padding-right: 0;
  }
}

.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.row {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  flex: 1;
  min-width: 12rem;
}

.field--narrow {
  flex: 0 0 11rem;
  min-width: 11rem;
}

.field label {
  font-size: 0.875rem;
  font-weight: 500;
}

.field input,
.field textarea {
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  resize: vertical;
}

.field__error {
  margin: 0;
  color: var(--color-danger);
  font-size: 0.825rem;
}

.actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.editor-skeleton {
  height: 22rem;
  border-radius: var(--radius);
}

.muted {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.add-quiz {
  margin-top: 2.5rem;
  padding-top: 1.5rem;
  border-top: 1px solid var(--color-border);
}

.button-plain {
  padding: 0.5rem 1rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  cursor: pointer;
}

@media (max-width: 48rem) {
  .page-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.5rem;
  }

  .back,
  .link {
    white-space: nowrap;
  }

  .row {
    flex-direction: column;
  }

  .field--narrow {
    flex: 1 1 auto;
    min-width: 0;
  }
}
</style>
