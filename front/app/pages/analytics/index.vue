<script setup lang="ts">
import type { BarRow } from '~/components/analytics/BarList.vue'
import type {
  LearningMaterialRow,
  LearningPerson,
  LearningQuizResult,
  LearningQuizRow,
  LearningSurveyParticipant,
  LearningSurveyRow,
} from '~/types/analytics'
import type { SurveySummary } from '~/types/survey'
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

const { fetchLearning, fetchLearningPeople, fetchQuizResults, fetchSurveyResults } = useAnalyticsApi()

const { data, pending, error } = await useAsyncData(
  'analytics-learning',
  async () => (await fetchLearning()).data,
)

const summary = computed(() => data.value?.summary ?? null)

/** Доля в процентах, где знаменатель может быть нулём. */
function share(part: number, whole: number): number {
  return whole === 0 ? 0 : Math.round((part / whole) * 100)
}

/* ---------- Люди за цифрой ---------- */

/**
 * Что стоит за раскрываемой цифрой.
 *
 * Заголовок и подписи колонок живут здесь, а не в разметке: колонка «что» и
 * колонка даты у срезов разные — у записи это курс и день записи, у аттестации
 * проверка и день отправки, — и «Материал · Когда» на всех означало бы таблицу,
 * не отвечающую ни на один из вопросов.
 */
const SLICES = {
  'attestations': { title: 'Работы, ждущие проверки', what: 'Проверка', when: 'Отправлена' },
  'not-started': { title: 'Записи, к которым не приступали', what: 'Курс', when: 'Записан' },
  'completed': { title: 'Пройденные курсы', what: 'Курс', when: 'Пройден' },
  'plan': { title: 'Шаги планов обучения', what: 'Материал', when: 'Назначен' },
  'learners': { title: 'Ученики', what: 'Курсы', when: 'Последний шаг' },
  'progress': { title: 'Все записи на курсы', what: 'Курс', when: 'Последний шаг' },
  'acknowledgements': { title: 'Ознакомления', what: 'Материал', when: 'Отмечено' },
} as const

type Slice = keyof typeof SLICES

const openedSlice = ref<Slice | null>(null)
const people = ref<LearningPerson[]>([])
const peopleTotal = ref(0)
const isLoadingPeople = ref(false)
const peopleError = ref<string | null>(null)

/**
 * Открыт ровно один список: семь таблиц подряд — не отчёт, а выгрузка.
 *
 * Открывается он окном поверх страницы, а не разворачивается под сеткой плиток
 * (решение пользователя 2026-09-20): ответ должен появляться там, куда человек
 * только что нажал, а не под нижним рядом, до которого ещё надо долистать.
 */
async function openSlice(slice: Slice) {
  openedSlice.value = slice
  people.value = []
  peopleError.value = null
  isLoadingPeople.value = true

  try {
    const answer = (await fetchLearningPeople(slice)).data

    people.value = answer.people
    peopleTotal.value = answer.total
  }
  catch {
    peopleError.value = 'Не удалось загрузить список.'
    openedSlice.value = null
  }
  finally {
    isLoadingPeople.value = false
  }
}

/**
 * Колонка прогресса — только там, где он есть.
 *
 * У ознакомлений и шагов плана доли нет вовсе, и пустой столбец из прочерков
 * сообщал бы, что её не посчитали, вместо того что её не бывает.
 */
const showsProgress = computed(() => people.value.some(person => person.progress !== null))

/** Подписи открытого среза: заголовок окна и две его колонки. */
const sliceMeta = computed(() => (openedSlice.value ? SLICES[openedSlice.value] : null))

function closeSlice() {
  openedSlice.value = null
  people.value = []
}

/** Состояние словом красится тем же набором бейджей, что и везде на странице. */
function stateClass(tone: LearningPerson['tone']): string {
  return tone === 'muted' ? 'badge' : `badge badge--${tone}`
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

/* ---------- Отчёт по опросам ---------- */

/**
 * Опросы — тем же списком, что и проверки, и с той же мыслью: как это проходят.
 * Первыми обязательные: от них зависит зачёт материала, и незакрытый
 * обязательный опрос это не цифра, а очередь людей, которым не зачлось.
 *
 * Раскрытая строка показывает две разные вещи под одним словом «результаты»:
 * сводку ответов — то, ради чего опрос заводили, — и список прошедших. У
 * анонимного опроса имён в сводке нет и взяться им неоткуда, а список прошедших
 * поимённый и у него: «кто прошёл, видно; что ответил — нет».
 */
const surveys = computed(() => data.value?.surveys ?? [])

const openedSurveyId = ref<number | null>(null)
const surveySummary = ref<SurveySummary | null>(null)
const surveyPeople = ref<LearningSurveyParticipant[]>([])
const isLoadingSurvey = ref(false)
const surveyError = ref<string | null>(null)

async function openSurvey(id: number) {
  if (openedSurveyId.value === id) {
    openedSurveyId.value = null
    surveySummary.value = null
    surveyPeople.value = []

    return
  }

  openedSurveyId.value = id
  surveySummary.value = null
  surveyPeople.value = []
  surveyError.value = null
  isLoadingSurvey.value = true

  try {
    const { data: result } = await fetchSurveyResults(id)

    surveySummary.value = result.summary
    surveyPeople.value = result.people
  }
  catch {
    surveyError.value = 'Не удалось загрузить результаты опроса.'
    openedSurveyId.value = null
  }
  finally {
    isLoadingSurvey.value = false
  }
}

/** Куда ведёт опрос: в урок курса, в документ, в его версию или в новость. */
function surveyLink(survey: LearningSurveyRow): string | null {
  if (survey.owner === 'lesson') {
    return survey.course_slug && survey.lesson_id
      ? `/lms/${survey.course_slug}/lessons/${survey.lesson_id}`
      : null
  }

  if (survey.owner === 'news') {
    return survey.news_slug ? `/news/${survey.news_slug}` : null
  }

  return survey.document_slug
    ? `/lms/${survey.document_kind === 'handbook' ? 'handbooks' : 'documents'}/${survey.document_slug}`
    : null
}

function surveyWhere(survey: LearningSurveyRow): string {
  const material = survey.material ?? 'источник удалён'

  if (survey.owner === 'lesson') {
    return `Урок «${material}»${survey.course_title ? ` · ${survey.course_title}` : ''}`
  }

  if (survey.owner === 'news') {
    return `Новость «${material}»`
  }

  const what = survey.document_kind === 'handbook' ? 'Справочник' : 'Документ'

  return survey.version_name
    ? `${what} «${material}» · версия «${survey.version_name}»`
    : `${what} «${material}»`
}

/** Закрыт ли опрос: срок приёма ответов мог выйти. */
function isClosed(survey: LearningSurveyRow): boolean {
  return survey.closes_at !== null && new Date(survey.closes_at).getTime() < Date.now()
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
          Из любой цифры можно провалиться в список — нажмите на плитку.
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
        expandable
        :hint="summary.attestations
          ? `Всего аттестаций: ${formatNumber(summary.attestations)}`
          : 'Аттестаций пока не заводили'"
        @open="openSlice('attestations')"
      />
      <AnalyticsStatTile
        label="Не приступали"
        :value="summary.not_started"
        :span="3"
        attention
        expandable
        :hint="`Из ${formatNumber(summary.enrollments)} ${pluralise(summary.enrollments, 'записи', 'записей', 'записей')} на курсы`"
        @open="openSlice('not-started')"
      />
      <AnalyticsStatTile
        label="Курсы пройдены"
        :value="share(summary.completed, summary.enrollments)"
        format="percent"
        :span="3"
        expandable
        :hint="`${formatNumber(summary.completed)} из ${formatNumber(summary.enrollments)} записей`"
        @open="openSlice('completed')"
      />
      <AnalyticsStatTile
        label="План обучения пройден"
        :value="share(summary.plan_done, summary.plan_steps)"
        format="percent"
        :span="3"
        expandable
        :hint="`${formatNumber(summary.plan_done)} из ${formatNumber(summary.plan_steps)} шагов у ${formatNumber(summary.plan_people)} человек`"
        @open="openSlice('plan')"
      />

      <AnalyticsStatTile
        label="Учеников"
        :value="summary.learners"
        :span="3"
        expandable
        :hint="`${share(summary.learners, summary.staff)}% сотрудников хотя бы на одном курсе`"
        @open="openSlice('learners')"
      />
      <AnalyticsStatTile
        label="Средний прогресс"
        :value="summary.average_progress"
        format="percent"
        :span="3"
        expandable
        hint="Доля пройденных уроков по всем записям"
        @open="openSlice('progress')"
      />
      <!-- Проверки раскрываются не списком людей, а таблицей ниже: она уже
           стоит на странице, и вторая такая же под плиткой спорила бы с ней. -->
      <AnalyticsStatTile
        label="Проверки сдают"
        :value="share(summary.quiz_passed, summary.quiz_attempts)"
        format="percent"
        :span="3"
        to="#quizzes"
        :hint="`${formatNumber(summary.quiz_passed)} из ${formatNumber(summary.quiz_attempts)} попыток, средний балл ${summary.quiz_average_score}`"
      />
      <!-- Опросы раскрываются таблицей ниже, как и проверки: доли у опроса нет
           — планки и балла у него не бывает, — поэтому на плитке счёт. -->
      <AnalyticsStatTile
        v-if="summary.surveys"
        label="Опросы прошли"
        :value="summary.survey_answered"
        :span="3"
        to="#surveys"
        :hint="`${formatNumber(summary.surveys)} ${pluralise(summary.surveys, 'опрос', 'опроса', 'опросов')}, из них обязательных ${formatNumber(summary.surveys_required)}`"
      />
      <AnalyticsStatTile
        label="Ознакомлений"
        :value="summary.acknowledgements"
        :span="3"
        expandable
        :hint="`Отметились ${formatNumber(summary.acknowledged_by)} ${pluralise(summary.acknowledged_by, 'человек', 'человека', 'человек')}`"
        @open="openSlice('acknowledgements')"
      />

      <!--
        Сколько материала собрано: справка, а не повод действовать. Раскрывать
        под ними нечего — за ними стоит раздел, и туда они и ведут: второй,
        худший каталог под плиткой не нужен никому.
      -->
      <AnalyticsStatTile
        label="Курсов"
        :value="summary.courses"
        :span="3"
        to="/lms"
        :hint="`Опубликовано ${formatNumber(summary.published_courses)} · ${formatNumber(summary.lessons)} ${pluralise(summary.lessons, 'урок', 'урока', 'уроков')}`"
      />
      <AnalyticsStatTile
        label="Документов"
        :value="summary.documents"
        :span="3"
        to="/lms/documents"
        :hint="summary.versions
          ? `Опубликовано ${formatNumber(summary.published_documents)} · ${formatNumber(summary.versions)} ${pluralise(summary.versions, 'версия', 'версии', 'версий')}`
          : `Опубликовано ${formatNumber(summary.published_documents)}`"
      />
      <AnalyticsStatTile
        label="Справочников"
        :value="summary.handbooks"
        :span="3"
        to="/lms/handbooks"
        :hint="`Опубликовано ${formatNumber(summary.published_handbooks)}`"
      />
      <AnalyticsStatTile
        label="Сотрудников"
        :value="summary.staff"
        :span="3"
        to="/staff"
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

      <!-- Имя якоря: сюда ведёт плитка «Проверки сдают» — раскрывать под ней
           второй такой же список незачем, он уже здесь. -->
      <AnalyticsChartCard
        id="quizzes"
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

      <!-- Имя якоря: сюда ведёт плитка «Опросы прошли». -->
      <AnalyticsChartCard
        id="surveys"
        title="Опросы: что ответили"
        hint="Первыми обязательные — от них зависит зачёт материала. Раскройте строку: сводка ответов и кто опрос прошёл. У анонимного опроса имён в ответах нет"
        :span="12"
        :rows="4"
      >
        <table v-if="surveys.length" class="quizzes data-table">
          <thead>
            <tr>
              <th>Опрос</th>
              <th>Вопросов</th>
              <th>Прошли</th>
              <th>Какой</th>
            </tr>
          </thead>

          <tbody>
            <template v-for="survey in surveys" :key="survey.id">
              <tr class="quizzes__row" :class="{ 'quizzes__row--open': openedSurveyId === survey.id }">
                <td>
                  <button
                    type="button"
                    class="quizzes__open"
                    :aria-expanded="openedSurveyId === survey.id"
                    @click="openSurvey(survey.id)"
                  >
                    {{ survey.title }}
                  </button>

                  <NuxtLink v-if="surveyLink(survey)" :to="surveyLink(survey)!" class="muted quizzes__where">
                    {{ surveyWhere(survey) }}
                  </NuxtLink>
                  <span v-else class="muted quizzes__where">{{ surveyWhere(survey) }}</span>
                </td>
                <td class="data-table__number" data-label="Вопросов">
                  {{ survey.questions }}
                </td>
                <td class="data-table__number" data-label="Прошли">
                  {{ survey.answered }}
                </td>
                <td data-label="Какой">
                  <!-- Три пометки, и каждая объясняет читателю разное: почему
                       людей спрашивают, почему в ответах нет имён и почему
                       ответов больше не прибавится. -->
                  <span v-if="survey.is_required" class="badge badge--accent">обязательный</span>
                  <span v-if="survey.is_anonymous" class="badge">анонимный</span>
                  <span v-if="isClosed(survey)" class="badge badge--warning">закрыт</span>
                  <span v-if="!survey.is_required && !survey.is_anonymous && !isClosed(survey)" class="muted">—</span>
                </td>
              </tr>

              <tr v-if="openedSurveyId === survey.id" class="quizzes__people">
                <td colspan="4" class="data-table__span">
                  <p v-if="isLoadingSurvey" class="muted">
                    Загружаем…
                  </p>
                  <p v-else-if="surveyError" class="alert alert--danger" role="alert">
                    {{ surveyError }}
                  </p>
                  <template v-else>
                    <!-- Сводка — та же, что автор видит в редакторе материала:
                         распределение по вариантам, среднее по шкале,
                         написанное списком. -->
                    <SurveySummaryView v-if="surveySummary" :summary="surveySummary" />

                    <p v-if="!surveyPeople.length" class="muted">
                      Этот опрос ещё никто не прошёл.
                    </p>
                    <ul v-else class="people survey-people">
                      <li v-for="person in surveyPeople" :key="person.id" class="person">
                        <NuxtLink :to="`/staff/${person.id}`" class="person__name">
                          {{ person.name }}
                        </NuxtLink>
                        <span v-if="person.answered_at" class="muted">{{ when(person.answered_at) }}</span>
                      </li>
                    </ul>
                  </template>
                </td>
              </tr>
            </template>
          </tbody>
        </table>

        <UiEmptyState
          v-else
          title="Опросов пока нет"
          description="Приложите опрос к уроку, документу, справочнику или новости — ответы появятся здесь."
        />
      </AnalyticsChartCard>
    </AnalyticsBentoGrid>

    <!--
      Список за цифрой — окном поверх страницы, а не панелью под сеткой
      плиток (решение пользователя 2026-09-20). Прежде он вставал под всеми
      плитками, потому что таблица посреди двенадцатиколоночной сетки
      разорвала бы ряд надвое, — и ответ оказывался в экране от вопроса.
    -->
    <AppSheet
      :open="openedSlice !== null"
      wide
      :title="sliceMeta?.title ?? ''"
      :hint="peopleTotal > people.length
        ? `Показаны первые ${formatNumber(people.length)} из ${formatNumber(peopleTotal)} — дальше читать невозможно`
        : 'Уволенные не в счёт: отчёт о тех, кого можно спросить'"
      @close="closeSlice"
    >
      <p v-if="isLoadingPeople" class="muted">
        Загружаем…
      </p>

      <p v-else-if="peopleError" class="alert alert--danger" role="alert">
        {{ peopleError }}
      </p>

      <UiEmptyState
        v-else-if="!people.length"
        title="Никого"
        description="За этой цифрой сейчас никто не стоит."
      />

      <table v-else class="slice data-table">
        <thead>
          <tr>
            <th>Сотрудник</th>
            <th>{{ sliceMeta?.what }}</th>
            <th>Состояние</th>
            <th v-if="showsProgress">
              Прогресс
            </th>
            <th>{{ sliceMeta?.when }}</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="(person, index) in people" :key="`${person.user_id}-${index}`">
            <td>
              <NuxtLink :to="`/staff/${person.user_id}`" class="person__name">
                {{ person.name }}
              </NuxtLink>
            </td>
            <td data-label="Что">
              <NuxtLink v-if="person.path" :to="person.path" class="slice__material">
                {{ person.title }}
              </NuxtLink>
              <span v-else>{{ person.title }}</span>
            </td>
            <td data-label="Состояние">
              <span :class="stateClass(person.tone)">{{ person.state }}</span>
            </td>
            <td v-if="showsProgress" class="data-table__number" data-label="Прогресс">
              {{ person.progress === null ? '—' : `${person.progress}%` }}
            </td>
            <td class="data-table__number" data-label="Когда">
              {{ when(person.at) || '—' }}
            </td>
          </tr>
        </tbody>
      </table>
    </AppSheet>
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

/* Прошедшие опрос — под сводкой ответов, а не вместо неё: отчёт открывают ради
   сказанного, список отвечает на второй вопрос, «кто именно». */
.survey-people {
  margin-top: 0.75rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--color-border);
}

/* Список за раскрытой цифрой: то же, что у отчёта по проверкам, — числа рядом
   друг с другом, на телефоне строка разворачивается в карточку. */
.slice {
  font-size: 0.92rem;
}

/* Ссылка на материал подчёркивается только под курсором: в столбце из двухсот
   строк постоянное подчёркивание превращает таблицу в сплошную линию. */
.slice__material {
  color: inherit;
  text-decoration: none;
}

.slice__material:hover {
  text-decoration: underline;
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
