<script setup lang="ts">
import type { LearningSurveyParticipant, LearningSurveyRow } from '~/types/analytics'
import type { SurveySummary } from '~/types/survey'

definePageMeta({ middleware: 'auth', permission: 'enrollments.manage' })
useHead({ title: 'Результаты опросов — База знаний' })

const { can } = useAuth()
const { fetchSurveyResultsList } = useSurveyApi()
const { fetchSurveyResults } = useAnalyticsApi()
const page = ref(1)
const search = ref('')
const appliedSearch = ref('')
const { data, pending, error, refresh } = await useAsyncData('survey-results-list',
  () => fetchSurveyResultsList(page.value, appliedSearch.value), { watch: [page, appliedSearch] })
const selected = ref<number | null>(null)
const summary = ref<SurveySummary | null>(null)
const people = ref<LearningSurveyParticipant[]>([])
const loadingResults = ref(false)
const resultsError = ref<string | null>(null)
let requestId = 0

function find() {
  page.value = 1
  appliedSearch.value = search.value.trim()
}

watch([page, appliedSearch], () => {
  requestId++
  selected.value = null
  loadingResults.value = false
})

async function open(id: number) {
  const current = ++requestId
  if (selected.value === id) {
    selected.value = null
    loadingResults.value = false
    return
  }
  selected.value = id
  summary.value = null
  people.value = []
  resultsError.value = null
  loadingResults.value = true
  try {
    const result = (await fetchSurveyResults(id)).data
    if (current !== requestId) return
    summary.value = result.summary
    people.value = result.people
  }
  catch {
    if (current === requestId) resultsError.value = 'Не удалось загрузить результаты. Попробуйте ещё раз.'
  }
  finally {
    if (current === requestId) loadingResults.value = false
  }
}

function source(row: LearningSurveyRow) {
  const kind = row.owner === 'lesson' ? 'Урок' : row.owner === 'news' ? 'Новость'
    : row.document_kind === 'handbook' ? 'Справочник' : 'Документ'
  return `${kind}: ${row.material ?? 'источник удалён'}${row.version_name ? ` · версия «${row.version_name}»` : ''}${row.course_title ? ` · ${row.course_title}` : ''}`
}

function when(value: string) {
  return new Date(value).toLocaleString('ru-RU', { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <main class="survey-results">
    <header>
      <h1 class="page-title">Результаты опросов</h1>
      <p class="faint">Ответы на опросы из уроков, документов, справочников и новостей, включая версии материалов.</p>
    </header>

    <form class="search" role="search" @submit.prevent="find">
      <label for="survey-search">Название опроса</label>
      <div class="search__row">
        <input id="survey-search" v-model="search" class="input" type="search" maxlength="200" placeholder="Найти опрос">
        <button class="button-primary" type="submit">Найти</button>
      </div>
    </form>

    <p v-if="pending" role="status">Загружаем опросы…</p>
    <div v-else-if="error" class="alert alert--danger" role="alert">
      <p>Не удалось загрузить опросы.</p>
      <button class="button-secondary" type="button" @click="refresh()">Повторить</button>
    </div>
    <template v-else>
      <p class="faint">Найдено: {{ data?.meta.total ?? 0 }}</p>
      <UiEmptyState v-if="!data?.data.length" title="Опросов не найдено" description="Измените поиск или добавьте опрос в учебный материал." />
      <article v-for="survey in data?.data" :key="survey.id" class="card result">
        <header class="result__head">
          <div>
            <h2>{{ survey.title }}</h2>
            <p class="faint">{{ source(survey) }}</p>
            <p class="result__marks">
              <span>{{ survey.questions }} вопросов</span>
              <span>{{ survey.answered }} работающих сотрудников прошли</span>
              <span v-if="survey.is_required" class="badge badge--accent">Обязательный</span>
              <span v-if="survey.is_anonymous" class="badge">Анонимный</span>
              <span v-if="survey.closes_at" class="faint">Приём ответов до {{ when(survey.closes_at) }}</span>
            </p>
          </div>
          <button type="button" class="button-secondary" :aria-expanded="selected === survey.id" :aria-controls="`survey-results-${survey.id}`" @click="open(survey.id)">
            {{ selected === survey.id ? 'Скрыть результаты' : 'Посмотреть результаты' }}
          </button>
        </header>
        <section v-if="selected === survey.id" :id="`survey-results-${survey.id}`" class="result__body" :aria-label="`Результаты: ${survey.title}`">
          <p v-if="loadingResults" role="status">Загружаем ответы…</p>
          <div v-else-if="resultsError" role="alert">
            <p>{{ resultsError }}</p>
            <button class="button-secondary" type="button" @click="selected = null; open(survey.id)">Повторить</button>
          </div>
          <template v-else-if="summary">
            <SurveySummaryView :summary="summary" />
            <h3>Участники — работающие сотрудники</h3>
            <p v-if="!people.length" class="faint">Пока нет участников среди работающих сотрудников.</p>
            <ul v-else class="participants">
              <li v-for="person in people" :key="person.id">
                <NuxtLink v-if="can('users.view')" :to="`/staff/${person.id}`">{{ person.name }}</NuxtLink>
                <span v-else>{{ person.name }}</span>
                <time v-if="person.answered_at" :datetime="person.answered_at" class="faint">{{ when(person.answered_at) }}</time>
              </li>
            </ul>
          </template>
        </section>
      </article>
      <nav v-if="data && data.meta.last_page > 1" class="pagination" aria-label="Страницы опросов">
        <button class="button-secondary" type="button" :disabled="page <= 1" @click="page--">Назад</button>
        <span>Страница {{ page }} из {{ data.meta.last_page }}</span>
        <button class="button-secondary" type="button" :disabled="page >= data.meta.last_page" @click="page++">Далее</button>
      </nav>
    </template>
  </main>
</template>

<style scoped>
.survey-results { max-width: 1200px; margin: 0 auto; padding: 1.5rem; display: grid; gap: 1.25rem; }
h1, h2, h3, p { margin: 0; }
h2 { font-size: 1.2rem; overflow-wrap: anywhere; }
header > p { margin-top: .6rem; }
.search { display: grid; gap: .5rem; max-width: 650px; }
.search__row, .pagination { display: flex; align-items: center; gap: .75rem; }
.search__row input { flex: 1; min-width: 0; }
.result { padding: 1.25rem; }
.result__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; }
.result__head > div { min-width: 0; }
.result__head p { margin-top: .6rem; }
.result__head button { flex-shrink: 0; }
.result__marks { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem 1rem; font-size: .875rem; }
.result__body { margin-top: 1.25rem; border-top: 1px solid var(--color-border); padding-top: 1.25rem; display: grid; gap: 1rem; }
.participants { list-style: none; padding: 0; margin: 0; display: grid; gap: .75rem; }
.participants li { display: flex; justify-content: space-between; flex-wrap: wrap; gap: .5rem; }
.pagination { justify-content: center; }
@media (max-width: 700px) { .survey-results { padding: 1rem; } .result__head { flex-direction: column; } }
</style>
