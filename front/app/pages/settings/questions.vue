<script setup lang="ts">
import type { ConsultantOutcome, ConsultantQuestion } from '~/types/ai'
import type { Course, LessonSummary } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.update' })
useHead({ title: 'Вопросы к консультанту' })

const { fetchQuestions, resolveQuestion, deleteQuestion } = useAiApi()
const { confirm } = useAppDialog()
const { fetchCourses, fetchCourse } = useLmsApi()

const outcome = ref<ConsultantOutcome | ''>('')
const unanswered = ref(true)
const requestedOnly = ref(false)
const search = ref('')
const page = ref(1)

// Смена фильтра переносит на первую страницу: третья страница «всех вопросов»
// почти никогда не является третьей страницей отфильтрованных.
watch([outcome, unanswered, requestedOnly, search], () => {
  page.value = 1
})

const { data, pending, refresh } = await useAsyncData(
  'ai.questions',
  () => fetchQuestions({
    outcome: outcome.value || undefined,
    unanswered: unanswered.value ? 1 : undefined,
    requested: requestedOnly.value ? 1 : undefined,
    search: search.value || undefined,
    page: page.value > 1 ? page.value : undefined,
  }),
  { watch: [outcome, unanswered, requestedOnly, search, page] },
)

const questions = computed<ConsultantQuestion[]>(() => data.value?.data ?? [])
const summary = computed(() => data.value?.meta.summary)
const lastPage = computed(() => data.value?.meta.last_page ?? 1)

const outcomeOptions = computed(() => [
  { value: '', label: 'Любой исход' },
  ...(data.value?.meta.outcomes ?? []).map(item => ({ value: item.value, label: item.label })),
])

/** Исходы, ради которых журнал и открывают. */
const gaps = computed(() =>
  (summary.value?.['nothing-found'] ?? 0)
  + (summary.value?.suggested ?? 0)
  + (summary.value?.unused ?? 0),
)

function badgeClass(value: ConsultantOutcome): string {
  return {
    'answered': 'badge--success',
    'verbatim': 'badge--success',
    'nothing-found': 'badge--warning',
    'suggested': 'badge--warning',
    'unused': 'badge--warning',
    'failed': 'badge--danger',
  }[value] ?? ''
}

/**
 * Ответ автора на заданный вопрос.
 *
 * Форма раскрывается под самим вопросом, а не в отдельном окне: отвечают,
 * перечитывая, о чём спрашивали, — и вопрос должен оставаться на виду.
 */
const answering = ref<number | null>(null)
const draft = reactive({ courseSlug: '', lessonId: 0, question: '', answer: '' })
const saving = ref(false)
const failure = ref('')

/** Курсы и уроки подтягиваются лениво: журнал открывают чаще, чем отвечают. */
const courses = ref<Course[]>([])
const lessons = ref<LessonSummary[]>([])
const loadingLessons = ref(false)

/**
 * Поиск по курсам, а не один длинный список.
 *
 * Список приходит страницами по пятнадцать, и на базе из полусотни курсов
 * нужного в нём просто не окажется — выпадающий список молча показывал бы
 * первую страницу как весь каталог.
 */
const courseSearch = ref('')
let searchTimer: ReturnType<typeof setTimeout> | undefined

watch(courseSearch, (value) => {
  clearTimeout(searchTimer)

  searchTimer = setTimeout(() => void loadCourses(value), 300)
})

async function loadCourses(search = ''): Promise<void> {
  const { data: list } = await fetchCourses(search ? { search } : {})

  courses.value = list
}

async function startAnswering(item: ConsultantQuestion): Promise<void> {
  answering.value = item.id
  failure.value = ''
  lessons.value = []
  courseSearch.value = ''

  Object.assign(draft, {
    courseSlug: '',
    lessonId: 0,
    // Вопрос сотрудника как заготовка, а не как есть: спрашивают вразнобой
    // («а сколько сохнет-то?»), и такую строку в таблице урока потом никто не
    // найдёт. Правится здесь же, до сохранения.
    question: item.searched_as ?? item.question,
    answer: '',
  })

  if (courses.value.length === 0) {
    await loadCourses()
  }
}

/** Уроки выбранного курса — плоским списком, модулями автор здесь не мыслит. */
watch(() => draft.courseSlug, async (slug) => {
  draft.lessonId = 0
  lessons.value = []

  if (!slug) {
    return
  }

  loadingLessons.value = true

  try {
    const { data: course } = await fetchCourse(slug)

    lessons.value = (course.modules ?? []).flatMap(module => module.lessons ?? [])
  }
  finally {
    loadingLessons.value = false
  }
})

const courseOptions = computed(() => [
  { value: '', label: 'Выберите курс' },
  ...courses.value.map(course => ({ value: course.slug, label: course.title })),
])

const lessonOptions = computed(() => [
  { value: 0, label: lessons.value.length ? 'Выберите урок' : 'В курсе нет уроков' },
  ...lessons.value.map(lesson => ({ value: lesson.id, label: lesson.title })),
])

const canSave = computed(() =>
  draft.lessonId > 0 && draft.question.trim().length > 0 && draft.answer.trim().length > 0,
)

async function saveAnswer(item: ConsultantQuestion): Promise<void> {
  if (!canSave.value || saving.value) {
    return
  }

  saving.value = true
  failure.value = ''

  try {
    await resolveQuestion(item.id, {
      lesson_id: draft.lessonId,
      question: draft.question.trim(),
      answer: draft.answer.trim(),
    })

    answering.value = null
    await refresh()
  }
  catch {
    failure.value = 'Не удалось занести ответ. Проверьте, что курс открыт вам на правку.'
  }
  finally {
    saving.value = false
  }
}

/**
 * Убирает вопрос из журнала.
 *
 * С подтверждением, и оно говорит правду: строка одна на двоих, и вместе с ней
 * вопрос пропадает из переписки того, кто его задал.
 */
async function remove(item: ConsultantQuestion): Promise<void> {
  const confirmed = await confirm({
    title: 'Удалить вопрос?',
    message: 'Строка одна на двоих: вопрос пропадёт и из переписки сотрудника.',
    confirmLabel: 'Удалить',
    danger: true,
  })

  if (!confirmed) {
    return
  }

  try {
    await deleteQuestion(item.id)
    await refresh()
  }
  catch {
    failure.value = 'Не удалось удалить вопрос.'
  }
}
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Вопросы к консультанту
        </h1>
        <p class="page-subtitle">
          Что спрашивают сотрудники и на чём консультант молчит. Это и есть список пробелов в базе знаний.
        </p>
      </div>
    </header>

    <div v-if="summary" class="summary">
      <div class="summary__card">
        <span class="summary__value">{{ summary.answered }}</span>
        <span class="summary__label">Ответил</span>
      </div>
      <div class="summary__card">
        <span class="summary__value">{{ summary['nothing-found'] }}</span>
        <span class="summary__label">Источник не найден</span>
      </div>
      <div class="summary__card">
        <span class="summary__value">{{ summary.unused }}</span>
        <span class="summary__label">Источник не использован</span>
      </div>
      <div class="summary__card">
        <span class="summary__value">{{ summary.suggested }}</span>
        <span class="summary__label">Показано близкое</span>
      </div>
      <!-- Заявки без срока давности: просьба трёхнедельной давности ждёт
           ответа так же, как вчерашняя. -->
      <div class="summary__card">
        <span class="summary__value">{{ summary.requests }}</span>
        <span class="summary__label">Заявок ждут ответа</span>
      </div>
      <p class="summary__note">
        За последние 14 дней. Без ответа осталось <b>{{ gaps }}</b> вопросов.
      </p>
    </div>

    <div class="filters">
      <input v-model="search" type="search" class="input" placeholder="Поиск по вопросу…">
      <UiSelect v-model="outcome" :options="outcomeOptions" auto />
      <label class="toggle">
        <input v-model="unanswered" type="checkbox">
        <span>Только без ответа</span>
      </label>
      <!-- За заявкой стоит живой человек, оставшийся без ответа, — этим она и
           отличается от догадки журнала о пробеле. -->
      <label class="toggle">
        <input v-model="requestedOnly" type="checkbox">
        <span>Только заявки</span>
      </label>
    </div>

    <div v-if="pending" class="stack">
      <div v-for="n in 3" :key="n" class="card">
        <div class="skeleton skeleton-line" />
      </div>
    </div>

    <UiEmptyState
      v-else-if="!questions.length"
      title="Пусто"
      description="Либо вопросов ещё не задавали, либо на все нашёлся ответ."
    />

    <div v-else class="stack">
      <article v-for="item in questions" :key="item.id" class="card question">
        <header class="question__head">
          <span class="question__text">{{ item.question }}</span>
          <span class="question__badges">
            <span v-if="item.requested_at && !item.resolved_at" class="badge badge--accent">Заявка</span>
            <span v-else-if="item.feedback" class="badge" :class="item.feedback === 'helpful' ? 'badge--success' : 'badge--warning'">
              {{ item.feedback_label }}
            </span>
            <span class="badge" :class="badgeClass(item.outcome)">{{ item.outcome_label }}</span>
          </span>
        </header>

        <!-- Чем искали, когда вопрос был продолжением разговора. Без этой
             строки «а сколько это сохнет?» стоит рядом с источниками про
             краску, и откуда они взялись, понять неоткуда. -->
        <p v-if="item.searched_as" class="question__searched faint">
          Искали: {{ item.searched_as }}
        </p>

        <p v-if="item.answer" class="question__answer">
          {{ item.answer }}
        </p>

        <!-- Чего не хватило, по словам самого спрашивавшего. -->
        <p v-if="item.request_note" class="question__note">
          «{{ item.request_note }}»
        </p>

        <p class="question__hint faint">
          {{ item.hint }}
        </p>

        <!-- Уже отвеченное: видно, что сделано и куда занесено. -->
        <p v-if="item.resolved_at" class="question__resolved">
          Ответ занесён<template v-if="item.resolution_lesson"> в урок «{{ item.resolution_lesson.title }}»</template>
          <template v-if="item.resolved_by"> — {{ item.resolved_by }}</template>
        </p>

        <div v-else-if="answering !== item.id" class="question__actions">
          <button type="button" class="button-secondary button-sm" @click="startAnswering(item)">
            Ответить в уроке
          </button>
          <button type="button" class="button-ghost button-sm" @click="remove(item)">
            Удалить
          </button>
        </div>

        <!--
          Форма раскрывается под самим вопросом, а не в отдельном окне:
          отвечают, перечитывая, о чём спрашивали.
        -->
        <div v-else class="reply">
          <input
            v-model="courseSearch"
            type="search"
            class="input"
            placeholder="Поиск курса…"
          >

          <div class="reply__row">
            <UiSelect v-model="draft.courseSlug" :options="courseOptions" />
            <UiSelect v-model="draft.lessonId" :options="lessonOptions" :disabled="!draft.courseSlug || loadingLessons" />
          </div>

          <label class="reply__field">
            <span class="reply__label">Вопрос в таблице урока</span>
            <input v-model="draft.question" type="text" class="input" maxlength="500">
          </label>

          <label class="reply__field">
            <span class="reply__label">Ответ</span>
            <textarea v-model="draft.answer" class="input" rows="4" maxlength="5000" />
          </label>

          <p v-if="failure" class="alert alert--danger" role="alert">
            {{ failure }}
          </p>

          <p class="reply__hint faint">
            Ответ станет строкой таблицы этого урока — консультант будет отвечать им сам, — и придёт
            сотруднику в переписку.
          </p>

          <div class="reply__actions">
            <button type="button" class="button-primary" :disabled="!canSave || saving" @click="saveAnswer(item)">
              {{ saving ? 'Заношу…' : 'Занести ответ' }}
            </button>
            <button type="button" class="button-ghost" @click="answering = null">
              Отмена
            </button>
          </div>
        </div>

        <footer class="question__meta faint">
          <span>Найдено фрагментов: {{ item.retrieved }}</span>
          <span>Использовано: {{ item.cited }}</span>
          <span v-if="item.model">{{ item.model }}</span>
          <span v-if="item.duration_ms">{{ (item.duration_ms / 1000).toFixed(1) }} с</span>
          <span v-if="item.asked_by">{{ item.asked_by }}</span>
          <span v-if="item.asked_at">{{ new Date(item.asked_at).toLocaleString('ru-RU') }}</span>
        </footer>
      </article>
    </div>

    <nav v-if="lastPage > 1" class="pager">
      <button type="button" class="button-secondary button-sm" :disabled="page <= 1" @click="page--">
        Назад
      </button>
      <span class="faint">{{ page }} из {{ lastPage }}</span>
      <button type="button" class="button-secondary button-sm" :disabled="page >= lastPage" @click="page++">
        Вперёд
      </button>
    </nav>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.5rem;
}

/* Карточек стало шесть; на узком экране им нужна мера поменьше, иначе
   последняя остаётся одна на строке. */
.summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(7.5rem, 1fr));
  gap: 0.6rem;
  margin-bottom: 1.5rem;
}

.summary__card {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.85rem 1rem;
  border-radius: var(--radius);
  background: var(--control-surface);
}

.summary__value {
  font-size: 1.5rem;
  font-weight: 600;
  line-height: 1.1;
}

.summary__label {
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.summary__note {
  grid-column: 1 / -1;
  margin: 0;
  font-size: 0.82rem;
  color: var(--color-text-faint);
}

.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.6rem;
  margin-bottom: 1rem;
}

.filters .input {
  flex: 1;
  min-width: 14rem;
}

.toggle {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.88rem;
  white-space: nowrap;
}

.stack {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.question {
  padding: 1rem 1.15rem;
}

/*
 * Значки уходят под вопрос, когда строка не вмещает и то и другое.
 *
 * Их теперь до трёх — исход, оценка сотрудника, заявка, — и на телефоне
 * неразрывный ряд справа сжимал вопрос до двух слов на строку.
 */
.question__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem 1rem;
}

.question__badges {
  flex-wrap: wrap;
}

.question__text {
  /* Занимает строку целиком, когда значки не помещаются рядом. */
  flex: 1 1 12rem;
  font-weight: 550;
}

.question__searched {
  margin: 0.4rem 0 0;
  font-size: 0.82rem;
}

.question__answer {
  margin: 0.6rem 0 0;
  font-size: 0.88rem;
  line-height: 1.5;
  color: var(--color-text-muted);
}

.question__badges {
  display: flex;
  flex-shrink: 0;
  gap: 0.4rem;
}

.question__note {
  margin: 0.5rem 0 0;
  font-size: 0.88rem;
  line-height: 1.5;
  color: var(--color-text);
}

.question__resolved {
  margin: 0.6rem 0 0;
  font-size: 0.85rem;
  color: var(--color-success, var(--color-text-muted));
}

.question__actions {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.75rem;
}

/* Форма ответа — утопленная панель, как редактор доступов в списке людей: так
   она читается продолжением карточки, а не новой карточкой поверх неё. */
.reply {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  margin-top: 0.8rem;
  padding: 1rem;
  border-radius: var(--radius);
  background: var(--color-surface-sunken);
}

.reply__row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
  gap: 0.6rem;
}

.reply__field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.reply__label {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--color-text-muted);
}

.reply__hint {
  margin: 0;
  font-size: 0.8rem;
  line-height: 1.45;
}

.reply__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

@media (max-width: 32rem) {
  /* Кнопки во всю ширину: на телефоне промахнуться мимо «Занести ответ»
     дороже, чем потратить строку. */
  .reply__actions button,
  .question__actions button {
    flex: 1;
  }
}

.question__hint {
  margin: 0.5rem 0 0;
  font-size: 0.82rem;
}

.question__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.9rem;
  margin-top: 0.75rem;
  padding-top: 0.65rem;
  border-top: 1px solid var(--color-border);
  font-size: 0.78rem;
}

.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.9rem;
  margin-top: 1.25rem;
}

.skeleton-line {
  width: 100%;
  height: 1.5rem;
}
</style>
