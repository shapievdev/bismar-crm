<script setup lang="ts">
import type { JSONContent } from '@tiptap/core'
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type {
  CoursePerson,
  CourseStatus,
  CourseVisibility,
  MaterialSection,
  QuizPayload,
  RegulationLink,
} from '~/types/lms'
import { type UploadedMedia, withResolvedMedia, withoutResolvedMedia } from '~/utils/editor/attachments'
import type { UploadOptions } from '~/utils/upload'

/**
 * Редактор документа или справочника — один экран на оба раздела.
 */
const props = defineProps<{ section: MaterialSection }>()

const copy = useMaterialSection(props.section)

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
  fetchRelated,
  updateRelated,
  searchRelatedCandidates,
  fetchQuestions,
  updateQuestions,
  searchQuestionCandidates,
  saveQuiz,
  deleteQuiz,
  fetchQuizStatistics,
  fetchQuizAttempt,
} = useMaterialsApi(props.section)

const router = useRouter()

const { data, error, refresh } = await useAsyncData(
  () => `lms.${props.section}.edit.${slug.value}`,
  () => fetchRegulation(slug.value),
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: copy.notFound, fatal: true })
}

const regulation = computed(() => data.value?.data ?? null)

useHead({ title: () => regulation.value ? `${regulation.value.title} — правка` : copy.editorTitle })

const { data: categoryData } = await useAsyncData(`lms.${props.section}.categories.edit`, () => fetchCategories())
const categories = computed(() => categoryData.value?.data ?? [])

/* ---------- Сам документ ---------- */

const form = reactive({
  title: '',
  summary: '',
  status: 'draft' as CourseStatus,
  visibility: 'public' as CourseVisibility,
  category_id: null as number | null,
  keywords: [] as string[],
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
  form.keywords = value.keywords ?? []

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
      keywords: form.keywords,
    })

    savedAt.value = new Date().toLocaleTimeString('ru-RU')
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = copy.saveFailed
    }
  }
  finally {
    isSaving.value = false
  }
}

async function remove() {
  await deleteRegulation(slug.value)
  await router.push(`/lms/${copy.section}`)
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

/* ---------- Соседи: «рядом по теме» ---------- */

/**
 * Список сохраняется сам, кнопки «Сохранить» не дожидаясь: связь взаимная, и
 * потерять её вместе с несохранённой правкой статьи было бы вдвойне обидно —
 * пропал бы блок и на соседней странице.
 */
const related = ref<RegulationLink[]>([])
const isLoadingRelated = ref(false)
const isSavingRelated = ref(false)
const relatedError = ref<string | null>(null)

async function loadRelated() {
  isLoadingRelated.value = true

  try {
    related.value = (await fetchRelated(slug.value)).data
  }
  finally {
    isLoadingRelated.value = false
  }
}

onMounted(() => void loadRelated())

async function saveRelated(next: RegulationLink[]) {
  isSavingRelated.value = true
  relatedError.value = null

  try {
    related.value = (await updateRelated(slug.value, next.map(document => document.id))).data
  }
  catch {
    relatedError.value = 'Не удалось сохранить список соседних документов.'
  }
  finally {
    isSavingRelated.value = false
  }
}

/*
 * «Частые вопросы» — второй такой же список, но односторонний и упорядоченный:
 * первым в нём стоит то, с чем приходят чаще. Сохраняется он так же сам, без
 * кнопки: потерять его вместе с несохранённой правкой статьи было бы обидно.
 */
const questions = ref<RegulationLink[]>([])
const isLoadingQuestions = ref(false)
const isSavingQuestions = ref(false)
const questionsError = ref<string | null>(null)

async function loadQuestions() {
  isLoadingQuestions.value = true

  try {
    questions.value = (await fetchQuestions(slug.value)).data
  }
  finally {
    isLoadingQuestions.value = false
  }
}

onMounted(() => void loadQuestions())

async function saveQuestions(next: RegulationLink[]) {
  isSavingQuestions.value = true
  questionsError.value = null

  try {
    questions.value = (await updateQuestions(slug.value, next.map(document => document.id))).data
  }
  catch {
    questionsError.value = 'Не удалось сохранить список частых вопросов.'
  }
  finally {
    isSavingQuestions.value = false
  }
}

/** Переставляет строку на шаг вверх или вниз — порядок здесь и есть смысл. */
function moveQuestion(document: RegulationLink, delta: number) {
  const from = questions.value.findIndex(one => one.id === document.id)
  const to = from + delta

  if (from === -1 || to < 0 || to >= questions.value.length) {
    return
  }

  const next = [...questions.value]

  next.splice(to, 0, ...next.splice(from, 1))

  void saveQuestions(next)
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

      <NuxtLink :to="`/lms/${copy.section}/${regulation.slug}`" class="button-secondary button-sm">
        Посмотреть
      </NuxtLink>
    </header>

    <p v-if="generalError" class="alert alert--danger" role="alert">
      {{ generalError }}
    </p>

    <section class="card editor-panel">
      <h2 class="editor-panel__title">
        {{ copy.materialLabel }}
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
        <!-- Обязательна: раздел открывается списком категорий, и материал без
             неё в навигации не существует. -->
        <CategoryTreeSelect
          id="category"
          v-model="form.category_id"
          :categories="categories"
          :allow-none="false"
        />
        <p v-if="errors.category_id?.length" class="field-error">
          {{ errors.category_id[0] }}
        </p>
      </div>

      <!-- Слова, которыми документ найдут поиском. Стоят до статьи: их
           придумывают, отвечая на вопрос «с чем сюда придут». -->
      <KeywordsField v-model="form.keywords" :errors="errors.keywords" />

      <div class="field">
        <span class="field-label">Статья</span>
        <ClientOnly>
          <EditorRichTextEditor
            v-model="document"
            :placeholder="copy.articlePlaceholder"
            :upload-image="(file, options) => uploadInline(file, options, 'Изображение в документе')"
            :upload-video="(file, options) => uploadInline(file, options, 'Видео в документе')"
          />
        </ClientOnly>
      </div>
    </section>

    <p v-if="peopleError" class="alert alert--danger" role="alert">
      {{ peopleError }}
    </p>

    <!--
      Настройки материала — сеткой, а не колонкой во всю ширину. В каждой из
      них две-три строки, и растянутая на всю страницу карточка ради двух строк
      отправляет соседнюю под сгиб. Столбцы складываются в один, когда ширины
      перестаёт хватать.
    -->
    <div class="settings">
      <section class="card editor-panel">
        <h2 class="editor-panel__title">
          Кому виден и когда
        </h2>

        <!-- Два вопроса рядом, пока хватает ширины. -->
        <div class="pair">
          <div class="field">
            <span class="field-label">Состояние</span>
            <label class="choice">
              <input v-model="form.status" type="radio" value="draft">
              Черновик — виден только тем, кто правит материалы
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
        </div>
      </section>

      <!-- Допущенные: право авторское, поэтому панель есть не у каждого редактора. -->
      <CoursePeoplePanel
        v-if="regulation.can_manage_access"
        title="Кто допущен"
        :note="form.visibility === 'private'
        ? null
        : 'Материал открыт всем — список ни на что не влияет, пока он не закрыт.'"
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

      <!-- Что читать рядом. Список сохраняется сразу — как и списки людей. -->
      <RelatedDocumentsPanel
        :section="copy.section"
        :documents="related"
        :is-loading="isLoadingRelated"
        :is-saving="isSavingRelated"
        :error-message="relatedError"
        :search="term => searchRelatedCandidates(slug, term).then(response => response.data)"
        @add="document => saveRelated([...related, document])"
        @remove="document => saveRelated(related.filter(one => one.id !== document.id))"
      />

      <!-- «Частые вопросы» — тот же список, но односторонний, упорядоченный и
           без границы разделов: к правилу прикалывают и справочник. -->
      <RelatedDocumentsPanel
        :section="copy.section"
        :documents="questions"
        :is-loading="isLoadingQuestions"
        :is-saving="isSavingQuestions"
        :error-message="questionsError"
        title="Частые вопросы"
        note="С чем на эту страницу приходят чаще всего. Каждая строка ведёт к другому материалу — своего раздела или чужого; первым ставьте самое частое."
        empty-note="Вопросов пока нет."
        ordered
        :search="term => searchQuestionCandidates(slug, term).then(response => response.data)"
        @add="document => saveQuestions([...questions, document])"
        @remove="document => saveQuestions(questions.filter(one => one.id !== document.id))"
        @move="moveQuestion"
      />

      <AttachmentManager
        :attachments="regulation.attachments ?? []"
        :upload-file="(file, description, options) => uploadAttachment(slug, file, description, options)"
        :rename-file="(id, description) => updateAttachment(slug, id, description)"
        :remove-file="(id) => deleteAttachment(slug, id)"
        :attach-drive-file="(file) => attachDriveFile(slug, file)"
        @changed="refresh"
      />

      <!-- Пока проверки нет — кнопка её завести, и она умещается в клетку
           сетки. Заведённая проверка встаёт ниже во всю ширину: там уже
           конструктор вопросов, а не две строки. -->
      <section v-if="!showQuizBuilder" class="card editor-panel add-quiz">
        <div>
          <h2 class="editor-panel__title">
            Проверка
          </h2>
          <p class="faint">
            Пока её нет, сотрудник отмечает ознакомление кнопкой. С проверкой
            кнопки не будет: {{ copy.materialLabel.toLowerCase() }} зачтётся,
            когда он ответит верно на все вопросы.
          </p>
        </div>

        <button type="button" class="button-secondary" @click="showQuizBuilder = true">
          Добавить проверку
        </button>
      </section>
    </div>

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


    <div class="actions">
      <button type="button" class="button-primary" :disabled="isSaving" @click="save">
        {{ isSaving ? 'Сохраняем…' : 'Сохранить' }}
      </button>
      <span v-if="savedAt" class="faint">Сохранено в {{ savedAt }}</span>
      <button type="button" class="button-ghost actions__remove" @click="remove">
        {{ copy.removeLabel }}
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
  gap: 0.75rem;
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

/*
 * Плотнее, чем было: в панели три-четыре строки, а прежние отступы отдавали ей
 * высоту, за которой соседние панели уходили под сгиб.
 *
 * Класс свой, а не общий `.panel`: правила из области видимости этого экрана
 * достаются и корню вложенного компонента, и общее имя молча перекраивало
 * панели людей и соседей — их содержимое сжималось до ширины текста.
 */
.editor-panel {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1.1rem 1.25rem;
  align-items: flex-start;
}

/*
 * Настройки материала — сеткой.
 *
 * Каждая карточка здесь в две-три строки, и колонкой во всю ширину они
 * разгоняли редактор на два экрана пустоты.
 *
 * Соседки по ряду одной высоты (решение пользователя): списки людей растут по
 * мере наполнения, и ряд из карточек вразнобой читается как сбой вёрстки, а не
 * как экономия места. Содержимое при этом остаётся вверху — тянется коробка, а
 * не строки внутри неё.
 *
 * Отступ сверху у панели людей — от страницы курса, где общего промежутка
 * нет; в сетке промежуток свой, и чужой здесь ни к чему.
 */
.settings {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(26rem, 1fr));
  gap: 0.75rem;
}

.settings > * {
  margin-top: 0;
}

/* Два вопроса рядом, пока хватает ширины. */
.pair {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
  gap: 0.75rem 1.5rem;
  width: 100%;
}

.editor-panel__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
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

  .editor-panel {
    padding: 1rem 1.05rem;
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
