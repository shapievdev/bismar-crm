<script setup lang="ts">
import type { BarRow } from '~/components/analytics/BarList.vue'
import type { LearningQuizResult, LearningQuizRow } from '~/types/analytics'
import { formatNumber } from '~/utils/numbers'

/**
 * Аналитика обучения.
 *
 * Отдельная вкладка, а не строка в продажном дашборде: там деньги из ClickHouse
 * и период, здесь — курсы из своей базы и прохождение, которое копится с начала
 * времён. Фильтра по датам поэтому нет: «курс за март» ничего не значит.
 */
definePageMeta({ middleware: 'auth', permission: 'enrollments.manage' })
useHead({ title: 'Обучение — Аналитика' })

const { fetchLearning } = useAnalyticsApi()

const { data, pending, error } = await useAsyncData(
  'analytics-learning',
  async () => (await fetchLearning()).data,
)

const summary = computed(() => data.value?.summary ?? null)

/* ---------- Отчёт по тестам ---------- */

/**
 * Тесты уроков и проверки документов одним списком: устройство у них общее, и
 * вопрос «как это проходят» тоже. Первым — тот, где больше не сдавших: отчёт
 * открывают ради них.
 */
const quizzes = computed(() => [...(data.value?.quizzes ?? [])]
  .sort((a, b) => (b.attempted - b.passed) - (a.attempted - a.passed)))

const { fetchQuizResults } = useAnalyticsApi()

const openedQuizId = ref<number | null>(null)
const results = ref<LearningQuizResult[]>([])
const isLoadingResults = ref(false)
const resultsError = ref<string | null>(null)

/** Состав раскрывается по одному тесту: пятнадцать списков людей — не отчёт. */
async function openResults(id: number) {
  if (openedQuizId.value === id) {
    openedQuizId.value = null
    results.value = []

    return
  }

  openedQuizId.value = id
  results.value = []
  resultsError.value = null
  isLoadingResults.value = true

  try {
    results.value = (await fetchQuizResults(id)).data.people
  }
  catch {
    resultsError.value = 'Не удалось загрузить результаты.'
    openedQuizId.value = null
  }
  finally {
    isLoadingResults.value = false
  }
}

/** Куда ведёт тест: в урок курса или в документ. */
function quizLink(quiz: LearningQuizRow): string | null {
  if (quiz.kind === 'lesson') {
    return quiz.course_slug && quiz.lesson_id
      ? `/lms/${quiz.course_slug}/lessons/${quiz.lesson_id}`
      : null
  }

  return quiz.document_slug ? `/lms/documents/${quiz.document_slug}` : null
}

function quizWhere(quiz: LearningQuizRow): string {
  const material = quiz.material ?? 'источник удалён'

  return quiz.kind === 'lesson'
    ? `Урок «${material}»${quiz.course_title ? ` · ${quiz.course_title}` : ''}`
    : `Документ «${material}»`
}

function when(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU') : ''
}

/** Доля в процентах, где знаменатель может быть нулём. */
function share(part: number, whole: number): number {
  return whole === 0 ? 0 : Math.round((part / whole) * 100)
}

/**
 * Курсы по числу записавшихся. Вторая величина строки — то, ради чего рейтинг
 * и открывают: сколько дошло до конца и как далеко ушли остальные.
 */
const courseRows = computed<BarRow[]>(() => (data.value?.courses ?? []).map(course => ({
  name: course.title,
  value: course.enrolled,
  meta: course.enrolled === 0
    ? `${formatNumber(course.lessons)} ${pluralise(course.lessons, 'урок', 'урока', 'уроков')}, никто не записан`
    : `завершили ${formatNumber(course.completed)} · прогресс ${course.average_progress}%`,
})))

const regulationRows = computed<BarRow[]>(() => (data.value?.regulations ?? []).map(regulation => ({
  name: regulation.title,
  value: regulation.acknowledged,
  meta: summary.value
    ? `${share(regulation.acknowledged, summary.value.staff)}% сотрудников`
    : undefined,
})))
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Обучение
        </h1>
        <p class="page-subtitle">
          Сколько собрано курсов и документов и как их проходят. Уволенные в счёт не идут — отчёт о тех, кого можно спросить.
        </p>
      </div>
    </header>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось посчитать аналитику обучения.
    </p>

    <div v-else-if="pending" class="skeleton skeleton-block" />

    <AnalyticsBentoGrid v-else-if="summary">
      <AnalyticsStatTile
        label="Курсов"
        :value="summary.courses"
        :span="2"
        :hint="`Опубликовано ${formatNumber(summary.published_courses)}`"
      />
      <AnalyticsStatTile
        label="Документов"
        :value="summary.regulations"
        :span="2"
        :hint="`Опубликовано ${formatNumber(summary.published_regulations)}`"
      />
      <AnalyticsStatTile
        label="Уроков"
        :value="summary.lessons"
        :span="2"
        hint="Во всех курсах, кроме удалённых"
      />
      <AnalyticsStatTile
        label="Учеников"
        :value="summary.learners"
        :span="3"
        :hint="`${share(summary.learners, summary.staff)}% сотрудников хотя бы на одном курсе`"
      />
      <AnalyticsStatTile
        label="Записей на курсы"
        :value="summary.enrollments"
        :span="3"
        :hint="`Завершено ${formatNumber(summary.completed)} — ${share(summary.completed, summary.enrollments)}%`"
      />

      <AnalyticsStatTile
        label="Средний прогресс"
        :value="summary.average_progress"
        format="percent"
        :span="3"
        hint="Доля пройденных уроков по всем записям"
      />
      <AnalyticsStatTile
        label="Тесты сдано"
        :value="share(summary.quiz_passed, summary.quiz_attempts)"
        format="percent"
        :span="3"
        :hint="`${formatNumber(summary.quiz_passed)} из ${formatNumber(summary.quiz_attempts)} попыток, средний балл ${summary.quiz_average_score}`"
      />
      <AnalyticsStatTile
        label="Ознакомлений с документами"
        :value="summary.acknowledgements"
        :span="3"
        :hint="`Отметились ${formatNumber(summary.acknowledged_by)} человек`"
      />
      <AnalyticsStatTile
        label="План обучения пройден"
        :value="share(summary.plan_done, summary.plan_steps)"
        format="percent"
        :span="3"
        :hint="`${formatNumber(summary.plan_done)} из ${formatNumber(summary.plan_steps)} шагов у ${formatNumber(summary.plan_people)} человек`"
      />

      <AnalyticsChartCard
        title="Курсы: кто записан"
        hint="Первым — тот, на который записалось больше всего людей"
        :span="6"
        :rows="3"
      >
        <AnalyticsBarList v-if="courseRows.length" :rows="courseRows" format="number" />
        <UiEmptyState v-else title="Курсов пока нет" description="Появятся здесь, как только их заведут." />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Документы: кто ознакомился"
        hint="Доля считается от числа работающих сотрудников"
        :span="6"
        :rows="3"
      >
        <AnalyticsBarList v-if="regulationRows.length" :rows="regulationRows" format="number" />
        <UiEmptyState v-else title="Документов пока нет" description="Появятся здесь, как только их заведут." />
      </AnalyticsChartCard>
      <AnalyticsChartCard
        title="Тесты: кто сдал"
        hint="Люди, а не попытки: сдал с третьего раза — сдал один человек. Раскройте строку, чтобы увидеть состав"
        :span="12"
        :rows="4"
      >
        <table v-if="quizzes.length" class="quizzes">
          <thead>
            <tr>
              <th>Тест</th>
              <th>Вопросов</th>
              <th>Проходили</th>
              <th>Сдали</th>
              <th>Средний балл</th>
            </tr>
          </thead>

          <tbody>
            <template v-for="quiz in quizzes" :key="quiz.id">
              <tr class="quizzes__row" :class="{ 'quizzes__row--open': openedQuizId === quiz.id }">
                <td>
                  <button
                    type="button"
                    class="quizzes__open"
                    :aria-expanded="openedQuizId === quiz.id"
                    @click="openResults(quiz.id)"
                  >
                    {{ quiz.title }}
                  </button>
                  <NuxtLink v-if="quizLink(quiz)" :to="quizLink(quiz)!" class="muted quizzes__where">
                    {{ quizWhere(quiz) }}
                  </NuxtLink>
                  <span v-else class="muted quizzes__where">{{ quizWhere(quiz) }}</span>
                </td>
                <td class="quizzes__number">
                  {{ quiz.questions }}
                </td>
                <td class="quizzes__number">
                  {{ quiz.attempted }}
                </td>
                <td class="quizzes__number">
                  {{ quiz.passed }}
                  <span v-if="quiz.attempted > quiz.passed" class="badge badge--warning">
                    не сдали {{ quiz.attempted - quiz.passed }}
                  </span>
                </td>
                <td class="quizzes__number">
                  {{ quiz.attempted ? `${quiz.average_score}%` : '—' }}
                </td>
              </tr>

              <!-- Состав: не сдавшие идут первыми, ради них отчёт и открывают. -->
              <tr v-if="openedQuizId === quiz.id" class="quizzes__people">
                <td colspan="5">
                  <p v-if="isLoadingResults" class="muted">
                    Загружаем…
                  </p>
                  <p v-else-if="resultsError" class="alert alert--danger" role="alert">
                    {{ resultsError }}
                  </p>
                  <p v-else-if="!results.length" class="muted">
                    Этот тест никто ещё не проходил.
                  </p>
                  <ul v-else class="people">
                    <li v-for="person in results" :key="person.id" class="person">
                      <NuxtLink :to="`/staff/${person.id}`" class="person__name">
                        {{ person.name }}
                      </NuxtLink>
                      <span class="badge" :class="person.passed ? 'badge--success' : 'badge--warning'">
                        {{ person.passed ? 'сдал' : 'не сдал' }}
                      </span>
                      <span class="muted">
                        лучший результат {{ person.best_score }}% ·
                        {{ person.attempts }} {{ pluralise(person.attempts, 'попытка', 'попытки', 'попыток') }}
                        <template v-if="person.last_at"> · {{ when(person.last_at) }}</template>
                      </span>
                    </li>
                  </ul>
                </td>
              </tr>
            </template>
          </tbody>
        </table>

        <UiEmptyState
          v-else
          title="Тестов пока нет"
          description="Приложите проверку к уроку или документу — результаты появятся здесь."
        />
      </AnalyticsChartCard>
    </AnalyticsBentoGrid>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.75rem;
}

.muted {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

/* Отчёт таблицей, а не полосками: здесь читают числа рядом друг с другом, а не
   сравнивают длины. Узкий экран прокручивает её, а не ломает страницу. */
.quizzes {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.92rem;
}

.quizzes th,
.quizzes td {
  padding: 0.6rem 0.7rem;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid var(--color-border);
}

.quizzes th {
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
  font-weight: 500;
}

.quizzes__number {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.quizzes__open {
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  font-weight: 500;
  text-align: left;
  cursor: pointer;
  text-decoration: underline;
  text-underline-offset: 0.2em;
}

.quizzes__where {
  display: block;
  margin-top: 0.15rem;
  text-decoration: none;
}

.quizzes__row--open td {
  border-bottom-color: transparent;
}

.quizzes__people td {
  padding-top: 0;
}

.people {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.person {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.person__name {
  color: inherit;
  text-decoration: none;
  font-weight: 500;
}

.person__name:hover {
  text-decoration: underline;
}

.skeleton-block {
  width: 100%;
  height: 18rem;
}
</style>
