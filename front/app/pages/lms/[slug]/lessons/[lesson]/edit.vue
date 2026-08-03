<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { JSONContent } from '@tiptap/core'
import type { LessonPayload, QuizPayload } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.update' })

const route = useRoute()
const { fetchLesson, updateLesson, saveQuiz, deleteQuiz, uploadAttachment, uploadVideo } = useLmsApi()

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

const document = ref<JSONContent | null>(lesson.value?.content_json ?? null)

/**
 * Media dropped into the article is stored as a normal lesson attachment, so
 * it is listed, replaceable and deleted along with the lesson. The editor only
 * needs the URL back.
 */
async function uploadInlineImage(file: File): Promise<string> {
  const { data } = await uploadAttachment(lessonId.value, file, 'Изображение в статье')

  return data.url
}

async function uploadInlineVideo(file: File): Promise<string> {
  const { data } = await uploadVideo(lessonId.value, file)

  return data.video_upload_url ?? ''
}

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSaving = ref(false)
const savedAt = ref<string | null>(null)

const quizErrors = ref<ValidationErrors>({})
const isSavingQuiz = ref(false)
const showQuizBuilder = ref(Boolean(lesson.value?.quiz))

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
      content_json: document.value,
      video_url: form.value.video_url || null,
    })

    await refresh()
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
      :lesson-id="lessonId"
      :attachments="lesson.attachments ?? []"
      @changed="refresh"
    />

    <QuizBuilder
      v-if="showQuizBuilder"
      :quiz="lesson.quiz ?? null"
      :errors="quizErrors"
      :is-submitting="isSavingQuiz"
      @save="persistQuiz"
      @remove="removeQuiz"
    />

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

.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 46rem;
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
</style>
