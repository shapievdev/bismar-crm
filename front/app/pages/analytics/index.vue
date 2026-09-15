<script setup lang="ts">
import type { BarRow } from '~/components/analytics/BarList.vue'
import type { LearningMaterialRow, LearningQuizResult, LearningQuizRow } from '~/types/analytics'
import { formatNumber } from '~/utils/numbers'

/**
 * Аналитика обучения — первое, что открывается в разделе.
 *
 * Первое, потому что курсы и документы касаются всей компании и меняются
 * каждую неделю, а витрина продаж — отдельный разговор и отдельное право.
 *
 * Здесь нет фильтра по датам, и это не упущение: продажи смотрят за период, а
 * прохождение копится с начала времён — «курс за март» не значит ничего.
 *
 * Всюду, где можно, стоит охват, а не счёт: «прошли семеро» ничего не сообщает,
 * пока не сказано, из скольких. Круг допущенных — записанные плюс те, кому
 * материал назначен планом; то же определение, что в поимённом отчёте внизу
 * страницы курса.
 */
definePageMeta({ middleware: 'auth', permission: 'enrollments.manage' })
useHead({ title: 'Обучение — Аналитика' })

const { fetchLearning, fetchQuizResults } = useAnalyticsApi()

const { data, pending, error } = await useAsyncData(
  'analytics-learning',
  async () => (await fetchLearning()).data,
)

const summary = computed(() => data.value?.summary ?? null)

/** Доля в процентах, где знаменатель может быть нулём. */
function share(part: number, whole: number): number {
  return whole === 0 ? 0 : Math.round((part / whole) * 100)
}

/* ---------- Рейтинги ---------- */

/**
 * Курсы. Первым — тот, где больше всего допущенных не дошло до конца: отчёт
 * открывают ради них, а не ради самого многолюдного курса.
 */
const courseRows = computed<BarRow[]>(() => (data.value?.courses ?? []).map(course => ({
  name: course.title,
  value: course.completed,
  /*
   * Полоска меряется кругом, а не числом прошедших: так видно долю, а курс с
   * тремя прошедшими из трёх не выглядит хуже курса с пятью из сорока.
   */
  total: course.audience,
  meta: course.audience === 0
    ? `${formatNumber(course.lessons)} ${pluralise(course.lessons, 'урок', 'урока', 'уроков')} · никому не назначен`
    : [
        `прошли ${course.completed} из ${course.audience}`,
        course.enrolled - course.started > 0 ? `не приступили ${course.enrolled - course.started}` : null,
        `прогресс ${course.average_progress}%`,
      ].filter(Boolean).join(' · '),
})))

function materialRows(rows: LearningMaterialRow[]): BarRow[] {
  return rows.map(material => ({
    name: material.title,
    value: material.acknowledged,
    total: material.audience,
    meta: material.audience === 0
      ? 'никто не читал и никому не назначен'
      : [
          `ознакомились ${material.acknowledged} из ${material.audience}`,
          material.versions > 0
            ? `${material.versions} ${pluralise(material.versions, 'версия', 'версии', 'версий')}`
            : null,
        ].filter(Boolean).join(' · '),
  }))
}

const documentRows = computed(() => materialRows(data.value?.documents ?? []))
const handbookRows = computed(() => materialRows(data.value?.handbooks ?? []))

/* ---------- Отчёт по проверкам ---------- */

/**
 * Проверки уроков, документов и их версий одним списком: устройство у них общее,
 * и вопрос «как это проходят» тоже. Первыми — те, где кто-то ждёт человека:
 * непроверенная аттестация это не цифра, а невыполненная работа.
 */
const quizzes = computed(() => data.value?.quizzes ?? [])

const openedQuizId = ref<number | null>(null)
const results = ref<LearningQuizResult[]>([])
const isLoadingResults = ref(false)
const resultsError = ref<string | null>(null)

/** Состав раскрывается по одной проверке: пятнадцать списков людей — не отчёт. */
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

/** Куда ведёт проверка: в урок курса, в документ или в его версию. */
function quizLink(quiz: LearningQuizRow): string | null {
  if (quiz.owner === 'lesson') {
    return quiz.course_slug && quiz.lesson_id
      ? `/lms/${quiz.course_slug}/lessons/${quiz.lesson_id}`
      : null
  }

  return quiz.document_slug
    ? `/lms/${quiz.document_kind === 'handbook' ? 'handbooks' : 'documents'}/${quiz.document_slug}`
    : null
}

function quizWhere(quiz: LearningQuizRow): string {
  const material = quiz.material ?? 'источник удалён'

  if (quiz.owner === 'lesson') {
    return `Урок «${material}»${quiz.course_title ? ` · ${quiz.course_title}` : ''}`
  }

  const what = quiz.document_kind === 'handbook' ? 'Справочник' : 'Документ'

  // У проверки версии — чья она: в документе с версиями иначе не понять, о
  // каком тексте речь.
  return quiz.version_name
    ? `${what} «${material}» · версия «${quiz.version_name}»`
    : `${what} «${material}»`
}

function when(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU') : ''
}

/**
 * Что написать в колонке «сдали».
 *
 * У аттестации «не сдал» до проверки ничего не значит: работа отправлена, а
 * человек её ещё не смотрел. Поэтому ждущие проверки считаются отдельно и не
 * попадают в не сдавших.
 */
function failedIn(quiz: LearningQuizRow): number {
  return Math.max(0, quiz.attempted - quiz.passed - quiz.pending)
}
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Обучение
        </h1>
        <p class="page-subtitle">
          Сколько собрано материала и как его проходят. Всюду, где можно, — доля от круга допущенных,
          а не голый счёт. Уволенные не в счёт: отчёт о тех, кого можно спросить.
        </p>
      </div>
    </header>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось посчитать аналитику обучения.
    </p>

    <div v-else-if="pending" class="skeleton skeleton-block" />

    <AnalyticsBentoGrid v-else-if="summary">
      <!--
        Наверху — то, что требует действия сегодня: незакрытые аттестации и
        назначенное, к чему не приступали. Сколько всего собрано курсов, можно
        посмотреть и ниже.
      -->
      <AnalyticsStatTile
        label="Аттестаций ждут проверки"
        :value="summary.attestations_pending"
        :span="3"
        attention
        :hint="summary.attestations
          ? `Всего аттестаций: ${formatNumber(summary.attestations)}`
          : 'Аттестаций пока не заводили'"
      />
      <AnalyticsStatTile
        label="Не приступали"
        :value="summary.not_started"
        :span="3"
        attention
        :hint="`Из ${formatNumber(summary.enrollments)} ${pluralise(summary.enrollments, 'записи', 'записей', 'записей')} на курсы`"
      />
      <AnalyticsStatTile
        label="Курсы пройдены"
        :value="share(summary.completed, summary.enrollments)"
        format="percent"
        :span="3"
        :hint="`${formatNumber(summary.completed)} из ${formatNumber(summary.enrollments)} записей`"
      />
      <AnalyticsStatTile
        label="План обучения пройден"
        :value="share(summary.plan_done, summary.plan_steps)"
        format="percent"
        :span="3"
        :hint="`${formatNumber(summary.plan_done)} из ${formatNumber(summary.plan_steps)} шагов у ${formatNumber(summary.plan_people)} человек`"
      />

      <AnalyticsStatTile
        label="Учеников"
        :value="summary.learners"
        :span="3"
        :hint="`${share(summary.learners, summary.staff)}% сотрудников хотя бы на одном курсе`"
      />
      <AnalyticsStatTile
        label="Средний прогресс"
        :value="summary.average_progress"
        format="percent"
        :span="3"
        hint="Доля пройденных уроков по всем записям"
      />
      <AnalyticsStatTile
        label="Проверки сдают"
        :value="share(summary.quiz_passed, summary.quiz_attempts)"
        format="percent"
        :span="3"
        :hint="`${formatNumber(summary.quiz_passed)} из ${formatNumber(summary.quiz_attempts)} попыток, средний балл ${summary.quiz_average_score}`"
      />
      <AnalyticsStatTile
        label="Ознакомлений"
        :value="summary.acknowledgements"
        :span="3"
        :hint="`Отметились ${formatNumber(summary.acknowledged_by)} ${pluralise(summary.acknowledged_by, 'человек', 'человека', 'человек')}`"
      />

      <!-- Сколько материала собрано: справка, а не повод действовать. -->
      <AnalyticsStatTile
        label="Курсов"
        :value="summary.courses"
        :span="3"
        :hint="`Опубликовано ${formatNumber(summary.published_courses)} · ${formatNumber(summary.lessons)} ${pluralise(summary.lessons, 'урок', 'урока', 'уроков')}`"
      />
      <AnalyticsStatTile
        label="Документов"
        :value="summary.documents"
        :span="3"
        :hint="summary.versions
          ? `Опубликовано ${formatNumber(summary.published_documents)} · ${formatNumber(summary.versions)} ${pluralise(summary.versions, 'версия', 'версии', 'версий')}`
          : `Опубликовано ${formatNumber(summary.published_documents)}`"
      />
      <AnalyticsStatTile
        label="Справочников"
        :value="summary.handbooks"
        :span="3"
        :hint="`Опубликовано ${formatNumber(summary.published_handbooks)}`"
      />
      <AnalyticsStatTile
        label="Сотрудников"
        :value="summary.staff"
        :span="3"
        hint="Работающих — уволенные в отчёте не участвуют"
      />

      <AnalyticsChartCard
        title="Курсы: кто дошёл до конца"
        hint="Полоска — доля от круга допущенных: записанные плюс те, кому курс назначен планом. Первым — курс, где не дошло больше всего"
        :span="12"
        :rows="3"
      >
        <AnalyticsBarList v-if="courseRows.length" :rows="courseRows" format="number" />
        <UiEmptyState v-else title="Курсов пока нет" description="Появятся здесь, как только их заведут." />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Документы: кто ознакомился"
        hint="Доля от круга допущенных. У документа с версиями каждый читает свою"
        :span="6"
        :rows="3"
      >
        <AnalyticsBarList v-if="documentRows.length" :rows="documentRows" format="number" />
        <UiEmptyState v-else title="Документов пока нет" description="Появятся здесь, как только их заведут." />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Справочники: кто ознакомился"
        hint="Отдельно от документов: это разные разделы со своими правами"
        :span="6"
        :rows="3"
      >
        <AnalyticsBarList v-if="handbookRows.length" :rows="handbookRows" format="number" />
        <UiEmptyState v-else title="Справочников пока нет" description="Появятся здесь, как только их заведут." />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Проверки: кто сдал"
        hint="Люди, а не попытки: сдал с третьего раза — сдал один человек. Первыми — те, где кто-то ждёт проверки. Раскройте строку, чтобы увидеть состав"
        :span="12"
        :rows="4"
      >
        <table v-if="quizzes.length" class="quizzes data-table">
          <thead>
            <tr>
              <th>Проверка</th>
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
                  <span v-if="quiz.is_attestation" class="badge badge--accent quizzes__kind">аттестация</span>

                  <NuxtLink v-if="quizLink(quiz)" :to="quizLink(quiz)!" class="muted quizzes__where">
                    {{ quizWhere(quiz) }}
                  </NuxtLink>
                  <span v-else class="muted quizzes__where">{{ quizWhere(quiz) }}</span>
                </td>
                <td class="data-table__number" data-label="Вопросов">
                  {{ quiz.questions }}
                </td>
                <td class="data-table__number" data-label="Проходили">
                  {{ quiz.attempted }}
                </td>
                <td class="data-table__number quizzes__passed" data-label="Сдали">
                  {{ quiz.passed }}
                  <!-- Ждущие проверки идут первыми и не попадают в не сдавших:
                       это невыполненная работа проверяющего, а не человека. -->
                  <span v-if="quiz.pending" class="badge badge--warning">
                    ждут проверки {{ quiz.pending }}
                  </span>
                  <span v-if="failedIn(quiz)" class="badge badge--danger">
                    не сдали {{ failedIn(quiz) }}
                  </span>
                </td>
                <td class="data-table__number" data-label="Средний балл">
                  {{ quiz.attempted ? `${quiz.average_score}%` : '—' }}
                </td>
              </tr>

              <!-- Состав: не сдавшие идут первыми, ради них отчёт и открывают. -->
              <tr v-if="openedQuizId === quiz.id" class="quizzes__people">
                <td colspan="5" class="data-table__span">
                  <p v-if="isLoadingResults" class="muted">
                    Загружаем…
                  </p>
                  <p v-else-if="resultsError" class="alert alert--danger" role="alert">
                    {{ resultsError }}
                  </p>
                  <p v-else-if="!results.length" class="muted">
                    Эту проверку никто ещё не проходил.
                  </p>
                  <ul v-else class="people">
                    <li v-for="person in results" :key="person.id" class="person">
                      <NuxtLink :to="`/staff/${person.id}`" class="person__name">
                        {{ person.name }}
                      </NuxtLink>
                      <span
                        class="badge"
                        :class="person.passed
                          ? 'badge--success'
                          : (person.awaiting ? 'badge--warning' : 'badge--danger')"
                      >
                        {{ person.passed ? 'сдал' : (person.awaiting ? 'ждёт проверки' : 'не сдал') }}
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
          title="Проверок пока нет"
          description="Приложите проверку к уроку, документу или его версии — результаты появятся здесь."
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
   сравнивают длины. На телефоне строка разворачивается в карточку — см.
   `.data-table` в общих стилях. */
.quizzes {
  font-size: 0.92rem;
}

/*
 * В ячейке «сдали» рядом с числом стоят бейджи, и переносить их можно.
 *
 * Запрет переноса, общий для числовых ячеек, здесь снимается: «ждут проверки 2»
 * и «не сдали 1» вместе шире любого столбца, и без переноса они распирали бы
 * таблицу до горизонтальной прокрутки. Моноширинные цифры остаются.
 */
.quizzes__passed {
  white-space: normal;
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

.quizzes__kind {
  margin-left: 0.4rem;
}

.quizzes__where {
  display: block;
  margin-top: 0.15rem;
  text-decoration: none;
}

/* Раскрытая строка и её состав — одно целое: черта между ними разрезала бы
   проверку пополам. На телефоне черту несёт сама строка, на широком — ячейки. */
.quizzes__row--open td,
.quizzes__row--open {
  border-bottom-color: transparent;
}

/* Телефон: между карточкой проверки и её составом нет и отступа — иначе список
   людей читается как отдельная запись, а не как содержимое предыдущей. */
@media (max-width: 40rem) {
  .quizzes__row--open {
    padding-bottom: 0;
  }

  .quizzes__people {
    padding-top: 0;
  }
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
