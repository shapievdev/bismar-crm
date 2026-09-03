<script setup lang="ts">
import type { JSONContent } from '@tiptap/core'
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { CoursePerson, CourseStatus, CourseVisibility, QuizPayload } from '~/types/lms'
import { type UploadedMedia, withResolvedMedia, withoutResolvedMedia } from '~/utils/editor/attachments'
import type { UploadOptions } from '~/utils/upload'

definePageMeta({ middleware: 'auth', permission: 'courses.update' })

const route = useRoute()
const slug = computed(() => String(route.params.slug))

const {
  fetchRegulation,
  updateRegulation,
  deleteRegulation,
  fetchCategories,
  uploadAttachment,
  updateAttachment,
  deleteAttachment,
  attachDriveFile,
  fetchMembers,
  updateMembers,
  searchMemberCandidates,
  fetchExperts,
  updateExperts,
  searchExpertCandidates,
  saveQuiz,
  deleteQuiz,
  fetchQuizStatistics,
  fetchQuizAttempt,
} = useRegulationsApi()

const router = useRouter()

const { data, error, refresh } = await useAsyncData(
  () => `lms.regulation.edit.${slug.value}`,
  () => fetchRegulation(slug.value),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Документ не найден', fatal: true })
}

const regulation = computed(() => data.value?.data ?? null)

useHead({ title: () => regulation.value ? `${regulation.value.title} — правка` : 'Правка документа' })

const { data: categoryData } = await useAsyncData('lms.regulation-categories.edit', () => fetchCategories())
const categories = computed(() => categoryData.value?.data ?? [])

/* ---------- Сам документ ---------- */

const form = reactive({
  title: '',
  summary: '',
  status: 'draft' as CourseStatus,
  visibility: 'public' as CourseVisibility,
  category_id: null as number | null,
})

const document = ref<JSONContent | null>(null)

watch(regulation, (value) => {
  if (!value) {
    return
  }

  form.title = value.title
  form.summary = value.summary ?? ''
  form.status = value.status
  form.visibility = value.visibility
  form.category_id = value.category?.id ?? null

  // Адреса вложенных картинок и видео живут час, а правило — годы: документ
  // хранит номера, и адрес подставляется на пути к редактору.
  document.value = withResolvedMedia(value.content_json ?? null, value.attachments ?? [])
}, { immediate: true })

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSaving = ref(false)
const savedAt = ref<string | null>(null)

async function save() {
  isSaving.value = true
  errors.value = {}
  generalError.value = null
  savedAt.value = null

  try {
    await updateRegulation(slug.value, {
      title: form.title,
      summary: form.summary || null,
      content_json: withoutResolvedMedia(document.value),
      status: form.status,
      visibility: form.visibility,
      category_id: form.category_id,
    })

    savedAt.value = new Date().toLocaleTimeString('ru-RU')
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить документ.'
    }
  }
  finally {
    isSaving.value = false
  }
}

async function remove() {
  await deleteRegulation(slug.value)
  await router.push('/lms/documents')
}

/* ---------- Проверка ---------- */

/**
 * Есть проверка — значит ознакомление засчитывается сдачей, а не нажатием
 * кнопки. Конструктор тот же, что у теста урока; планку он не спрашивает —
 * зачитывается всё при всех верных ответах.
 */
const quizErrors = ref<ValidationErrors>({})
const isSavingQuiz = ref(false)
const showQuizBuilder = ref(false)

watch(regulation, value => showQuizBuilder.value = Boolean(value?.quiz), { immediate: true })

async function persistQuiz(payload: QuizPayload) {
  isSavingQuiz.value = true
  quizErrors.value = {}

  try {
    await saveQuiz(slug.value, payload)
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      quizErrors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить проверку.'
    }
  }
  finally {
    isSavingQuiz.value = false
  }
}

async function dropQuiz() {
  isSavingQuiz.value = true

  try {
    await deleteQuiz(slug.value)
    showQuizBuilder.value = false
    await refresh()
  }
  catch {
    generalError.value = 'Не удалось удалить проверку.'
  }
  finally {
    isSavingQuiz.value = false
  }
}

/**
 * Вставленное в статью хранится обычным вложением: так у картинки есть номер,
 * переживающий подписанную ссылку, и уходит она вместе с документом.
 */
async function uploadInline(file: File, options: UploadOptions, label: string): Promise<UploadedMedia> {
  const { data: attachment } = await uploadAttachment(slug.value, file, label, options)
  await refresh()

  return { id: attachment.id, url: attachment.url }
}

/* ---------- Люди ---------- */

const members = ref<CoursePerson[]>([])
const experts = ref<CoursePerson[]>([])
const isLoadingPeople = ref(false)
const isSavingPeople = ref(false)
const peopleError = ref<string | null>(null)

async function loadPeople() {
  isLoadingPeople.value = true

  try {
    experts.value = (await fetchExperts(slug.value)).data

    // Список допущенных ведёт автор: другому редактору сервер откажет, и это
    // не ошибка экрана — панель просто не показывается.
    if (regulation.value?.can_manage_access) {
      members.value = (await fetchMembers(slug.value)).data
    }
  }
  finally {
    isLoadingPeople.value = false
  }
}

onMounted(() => void loadPeople())

async function saveMembers(next: CoursePerson[]) {
  isSavingPeople.value = true
  peopleError.value = null

  try {
    members.value = (await updateMembers(slug.value, next.map(person => person.id))).data
    await refresh()
  }
  catch {
    peopleError.value = 'Не удалось сохранить список допущенных.'
  }
  finally {
    isSavingPeople.value = false
  }
}

async function saveExperts(next: CoursePerson[]) {
  isSavingPeople.value = true
  peopleError.value = null

  try {
    experts.value = (await updateExperts(slug.value, next.map(person => person.id))).data
  }
  catch {
    peopleError.value = 'Не удалось сохранить список ответственных.'
  }
  finally {
    isSavingPeople.value = false
  }
}
</script>

<template>
  <section v-if="regulation" class="edit">
    <header class="head">
      <div>
        <h1 class="page-title">
          {{ form.title || 'Без названия' }}
        </h1>
        <p class="page-subtitle">
          {{ regulation.status_label }} · {{ regulation.visibility_label }}
          <template v-if="regulation.acknowledged_count !== undefined">
            · ознакомились {{ regulation.acknowledged_count }}
          </template>
        </p>
      </div>

      <NuxtLink :to="`/lms/documents/${regulation.slug}`" class="button-secondary button-sm">
        Посмотреть
      </NuxtLink>
    </header>

    <p v-if="generalError" class="alert alert--danger" role="alert">
      {{ generalError }}
    </p>

    <section class="card panel">
      <h2 class="panel__title">
        Документ
      </h2>

      <div class="field">
        <label class="field-label" for="title">Название</label>
        <input id="title" v-model.trim="form.title" class="input" maxlength="255">
        <p v-if="errors.title?.length" class="field-error">
          {{ errors.title[0] }}
        </p>
      </div>

      <div class="field">
        <label class="field-label" for="summary">
          Короткое описание <span class="field-optional">— строка для каталога</span>
        </label>
        <input id="summary" v-model.trim="form.summary" class="input" maxlength="500">
      </div>

      <div class="field">
        <label class="field-label" for="category">Категория</label>
        <CategoryTreeSelect id="category" v-model="form.category_id" :categories="categories" />
      </div>

      <div class="field">
        <span class="field-label">Статья</span>
        <ClientOnly>
          <EditorRichTextEditor
            v-model="document"
            placeholder="Текст правила. Можно вставить картинку или видео."
            :upload-image="(file, options) => uploadInline(file, options, 'Изображение в документе')"
            :upload-video="(file, options) => uploadInline(file, options, 'Видео в документе')"
          />
        </ClientOnly>
      </div>
    </section>

    <section class="card panel">
      <h2 class="panel__title">
        Кому виден и когда
      </h2>

      <div class="field">
        <span class="field-label">Состояние</span>
        <label class="choice">
          <input v-model="form.status" type="radio" value="draft">
          Черновик — виден только тем, кто правит документы
        </label>
        <label class="choice">
          <input v-model="form.status" type="radio" value="published">
          Опубликован
        </label>
        <label class="choice">
          <input v-model="form.status" type="radio" value="archived">
          В архиве
        </label>
      </div>

      <div class="field">
        <span class="field-label">Доступ</span>
        <label class="choice">
          <input v-model="form.visibility" type="radio" value="public">
          Всем, кто читает базу знаний
        </label>
        <label class="choice">
          <input v-model="form.visibility" type="radio" value="private">
          Только автору и допущенным
        </label>
      </div>
    </section>

    <p v-if="peopleError" class="alert alert--danger" role="alert">
      {{ peopleError }}
    </p>

    <!-- Допущенные: право авторское, поэтому панель есть не у каждого редактора. -->
    <CoursePeoplePanel
      v-if="regulation.can_manage_access"
      title="Кто допущен"
      :note="form.visibility === 'private'
        ? null
        : 'Документ открыт всем — список ни на что не влияет, пока он не закрыт.'"
      :people="members"
      :is-loading="isLoadingPeople"
      :is-saving="isSavingPeople"
      :fixed-name="regulation.author?.name ?? null"
      fixed-badge="Автор"
      empty-note="Кроме автора — никого."
      add-label="Добавить сотрудника"
      not-found-note="Никого не нашли."
      :search="term => searchMemberCandidates(slug, term).then(response => response.data)"
      @add="person => saveMembers([...members, person])"
      @remove="person => saveMembers(members.filter(one => one.id !== person.id))"
    />

    <CoursePeoplePanel
      title="Кто отвечает"
      note="К этим людям идут с вопросом, если написанного не хватило."
      :people="experts"
      :is-loading="isLoadingPeople"
      :is-saving="isSavingPeople"
      empty-note="Ответственных пока нет."
      add-label="Добавить ответственного"
      not-found-note="Никого не нашли."
      :search="term => searchExpertCandidates(slug, term).then(response => response.data)"
      @add="person => saveExperts([...experts, person])"
      @remove="person => saveExperts(experts.filter(one => one.id !== person.id))"
    />

    <AttachmentManager
      :attachments="regulation.attachments ?? []"
      :upload-file="(file, description, options) => uploadAttachment(slug, file, description, options)"
      :rename-file="(id, description) => updateAttachment(slug, id, description)"
      :remove-file="(id) => deleteAttachment(slug, id)"
      :attach-drive-file="(file) => attachDriveFile(slug, file)"
      @changed="refresh"
    />

    <QuizBuilder
      v-if="showQuizBuilder"
      :quiz="regulation.quiz ?? null"
      :errors="quizErrors"
      :is-submitting="isSavingQuiz"
      :fixed-passing-score="100"
      @save="persistQuiz"
      @remove="dropQuiz"
    />

    <!-- Разбор — только у сохранённой проверки: пока её нет, считать нечего.
         Ключ здесь и так открыт: автор видит верные ответы в самой проверке. -->
    <QuizStatisticsPanel
      v-if="regulation.quiz"
      :key="regulation.quiz.id"
      :load="async () => (await fetchQuizStatistics(slug)).data"
      :load-review="async id => (await fetchQuizAttempt(slug, id)).data.review ?? null"
    />

    <!-- Пока проверки нет — кнопка её завести. Сказано прямо, чем это кончится:
         кнопка «ознакомлен» у документа с проверкой пропадает. -->
    <section v-else class="card panel add-quiz">
      <div>
        <h2 class="panel__title">
          Проверка
        </h2>
        <p class="faint">
          Пока её нет, сотрудник отмечает ознакомление кнопкой. С проверкой
          кнопки не будет: документ зачтётся, когда он ответит верно на все
          вопросы.
        </p>
      </div>

      <button type="button" class="button-secondary" @click="showQuizBuilder = true">
        Добавить проверку
      </button>
    </section>

    <div class="actions">
      <button type="button" class="button-primary" :disabled="isSaving" @click="save">
        {{ isSaving ? 'Сохраняем…' : 'Сохранить' }}
      </button>
      <span v-if="savedAt" class="faint">Сохранено в {{ savedAt }}</span>
      <button type="button" class="button-ghost actions__remove" @click="remove">
        Удалить документ
      </button>
    </div>
  </section>
</template>

<style scoped>
.add-quiz {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
}

.edit {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.head a {
  text-decoration: none;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.4rem 1.5rem;
  align-items: flex-start;
}

.panel__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  width: 100%;
}

.field-optional {
  color: var(--color-text-faint);
  font-weight: 400;
}

.choice {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}

.actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.actions__remove {
  margin-left: auto;
  color: var(--color-danger);
}

@media (max-width: 48rem) {
  .head {
    flex-direction: column;
    align-items: stretch;
    gap: 0.6rem;
  }

  .head a {
    align-self: flex-start;
  }

  .panel {
    padding: 1.15rem 1.15rem 1.25rem;
  }
}

@media (max-width: 34rem) {
  .actions {
    flex-wrap: wrap;
  }

  .actions__remove {
    margin-left: 0;
    padding-left: 0;
  }
}
</style>
