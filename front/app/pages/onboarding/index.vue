<script setup lang="ts">
import type { FetchError } from 'ofetch'
import type { Quiz, QuizAttempt } from '~/types/lms'
import type { Journey } from '~/types/onboarding'

/**
 * Маршрут адаптации глазами сотрудника: приветствие, прогресс, карта,
 * карточка этапа и набор, который собирается к выпуску.
 *
 * Все цифры приезжают с сервера готовыми; после каждого действия сервер
 * присылает журнал заново — экран никогда не считает прогресс сам.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Моя адаптация' })

const api = useOnboardingApi()
const route = useRoute()
const { user } = useAuth()

const { data, pending, error, refresh } = await useAsyncData('onboarding.my', () => api.fetchMy())

const journey = computed<Journey | null>(() => data.value?.data ?? null)

/** Имя без отчества: обращение, а не строка из кадров. */
const firstName = computed(() => {
  const full = user.value?.name ?? journey.value?.employee.name ?? ''

  return full.split(/\s+/)[1] ?? full.split(/\s+/)[0] ?? ''
})

/* ---------- Выбранный этап ---------- */

const selectedId = ref<number | null>(Number(route.query.stage) || null)

const selectedStage = computed(() => {
  const stages = journey.value?.stages ?? []

  return stages.find(stage => stage.id === selectedId.value)
    // По умолчанию открыта ближайшая цель — то, над чем работать сейчас.
    ?? stages.find(stage => ['available', 'in_progress', 'quiz_failed'].includes(stage.status))
    ?? stages[stages.length - 1]
    ?? null
})

/** Следующий этап за выбранным — карточке, чтобы подписать, что дальше. */
const nextStage = computed(() => {
  const stages = journey.value?.stages ?? []
  const index = stages.findIndex(stage => stage.id === selectedStage.value?.id)

  return index >= 0 ? (stages[index + 1] ?? null) : null
})

/** Короткое ободрение по тому, сколько пройдено. */
const encouragement = computed(() => {
  const percent = journey.value?.progress.percent ?? 0

  if (percent === 0) {
    return 'Начните с первого дня — дальше станет проще.'
  }

  if (percent < 50) {
    return 'Основы уже позади. Впереди — практика и знакомства.'
  }

  if (percent < 100) {
    return 'Больше половины пути пройдено. Осталось немного!'
  }

  return 'Весь маршрут пройден. Добро пожаловать в команду!'
})

/* ---------- Действия ---------- */

const submittingTaskId = ref<number | null>(Number(route.query.stage) || null)
const actionError = ref<string | null>(null)

async function submitTask(taskId: number) {
  submittingTaskId.value = taskId
  actionError.value = null

  try {
    data.value = await api.submitTask(taskId)
  }
  catch (caught) {
    actionError.value = (caught as FetchError<{ message?: string }>).data?.message
      ?? 'Не удалось отметить задание.'
  }
  finally {
    submittingTaskId.value = null
  }
}

/* ---------- Тест этапа ---------- */

const quiz = ref<Quiz | null>(null)
const quizResult = ref<QuizAttempt | null>(null)
const isSubmittingQuiz = ref(false)

async function openQuiz() {
  if (!selectedStage.value) {
    return
  }

  actionError.value = null
  quizResult.value = null

  try {
    quiz.value = (await api.fetchStageQuiz(selectedStage.value.id)).data
  }
  catch (caught) {
    actionError.value = (caught as FetchError<{ message?: string }>).data?.message
      ?? 'Не удалось открыть тест.'
  }
}

async function submitQuiz(answers: Record<number, number[] | string | string[][]>) {
  if (!selectedStage.value) {
    return
  }

  isSubmittingQuiz.value = true
  actionError.value = null

  try {
    quizResult.value = (await api.submitStageQuiz(selectedStage.value.id, answers)).data
    await refresh()
  }
  catch (caught) {
    actionError.value = (caught as FetchError<{ message?: string }>).data?.message
      ?? 'Не удалось отправить ответы.'
  }
  finally {
    isSubmittingQuiz.value = false
  }
}

function closeQuiz() {
  quiz.value = null
  quizResult.value = null
}

function day(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) : ''
}
</script>

<template>
  <section>
    <UiEmptyState
      v-if="error"
      title="Маршрута пока нет"
      description="Когда вам назначат адаптацию, карта появится здесь."
    />

    <div v-else-if="pending" class="card">
      <div class="skeleton skeleton-line" />
    </div>

    <template v-else-if="journey">
      <!-- Приветствие: зачем всё это и где человек сейчас. -->
      <header class="hero">
        <div class="hero__body">
          <span class="hero__eyebrow">Адаптация</span>
          <h1 class="hero__title">
            {{ firstName ? `${firstName}, это ваш путь` : 'Ваш путь' }}
          </h1>
          <p class="hero__lead">
            <template v-if="journey.total_days">
              {{ journey.total_days }}
              {{ journey.total_days === 1 ? 'день' : (journey.total_days < 5 ? 'дня' : 'дней') }} —
              и вы станете частью команды.
            </template>
            <template v-else>
              Шаг за шагом — и вы станете частью команды.
            </template>
          </p>
        </div>

        <div class="hero__now">
          <span class="hero__day">
            <template v-if="journey.status === 'active' && journey.current_day">
              День {{ journey.current_day }}<template v-if="journey.total_days"> из {{ journey.total_days }}</template>
            </template>
            <template v-else>{{ journey.status_label }}</template>
          </span>
          <span v-if="journey.started_at" class="hero__since">старт {{ day(journey.started_at) }}</span>
        </div>
      </header>

      <div class="top">
        <div class="card tile">
          <span class="tile__icon tile__icon--trophy" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
              <path d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.396 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0" />
            </svg>
          </span>

          <div class="tile__body">
            <span class="tile__label">Общий прогресс</span>
            <UiProgressBar :value="journey.progress.percent" />
            <span class="faint small">{{ journey.progress.completed }} из {{ journey.progress.total }} этапов завершено</span>
          </div>

          <span class="tile__percent">{{ journey.progress.percent }}%</span>
        </div>

        <!-- Ближайшая цель: сервер уже решил, что делать дальше. -->
        <div class="card tile">
          <span class="tile__icon tile__icon--star" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
              <path d="M11.48 3.5a.56.56 0 0 1 1.04 0l2.12 4.6 5.02.6c.47.06.66.65.31.98l-3.7 3.45.98 4.96c.09.47-.4.83-.81.6L12 16.2l-4.44 2.5c-.41.23-.9-.13-.81-.6l.98-4.97-3.7-3.44c-.35-.33-.16-.92.31-.98l5.02-.6 2.12-4.6Z" />
            </svg>
          </span>

          <div class="tile__body">
            <span class="tile__label">{{ journey.next_action ? 'Ближайшая цель' : 'Как идут дела' }}</span>
            <span class="tile__goal">{{ journey.next_action?.label ?? encouragement }}</span>
            <span v-if="journey.next_action" class="faint small">{{ encouragement }}</span>
          </div>

          <NuxtLink
            v-if="journey.next_action?.url"
            :to="journey.next_action.url"
            class="button-primary tile__go"
          >
            Перейти
          </NuxtLink>
          <button v-else-if="journey.next_action?.journey_stage_id" type="button" class="button-primary tile__go" @click="selectedId = journey.next_action.journey_stage_id; closeQuiz()">К этапу</button>
        </div>
      </div>

      <p v-if="actionError" class="alert alert--danger" role="alert">
        {{ actionError }}
      </p>

      <div class="layout">
        <OnboardingJourneyMap
          class="layout__map"
          :stages="journey.stages"
          :selected-id="selectedStage?.id ?? null"
          @select="id => { selectedId = id; closeQuiz() }"
        />

        <div class="layout__detail">
          <!-- Тест поверх карточки: открыт — отвечаем, закрыт — карточка. -->
          <template v-if="quiz && selectedStage">
            <QuizRunner
              :quiz="quiz"
              :is-submitting="isSubmittingQuiz"
              :result="quizResult ? { score: quizResult.score, passed: quizResult.passed, review: quizResult.review } : null"
              rule=""
              :passed-note="journey.settings.passed_message"
              :failed-note="journey.settings.failed_message"
              @submit="submitQuiz"
              @retry="closeQuiz(); openQuiz()"
            />
            <button type="button" class="button-ghost" @click="closeQuiz">
              ← К этапу
            </button>
          </template>

          <OnboardingStageCard
            v-else-if="selectedStage"
            :stage="selectedStage"
            :next="nextStage"
            :load-review="async id => (await api.fetchAttempt(id)).data.review ?? null"
            :interactive="journey.status === 'active'"
            :submitting-task-id="submittingTaskId"
            @submit-task="submitTask"
            @open-quiz="openQuiz"
          />
        </div>
      </div>

      <section v-if="journey.stages.length && journey.graduation.items.length" class="card equipment">
        <h2>Ваш профессиональный набор</h2>
        <p v-if="journey.progress.percent === 100 && journey.status !== 'completed' && journey.requires_graduation" class="faint">Обучение пройдено. Осталось получить обязательные пункты набора.</p>
        <ul class="equipment__items">
          <li v-for="item in journey.graduation.items" :key="item.id" :class="{ 'equipment__done': item.confirmed_at }">
            <OnboardingKitIcon :icon="item.icon" class="equipment__icon" />
            <strong>{{ item.label }}</strong>
            <span>{{ item.confirmed_at ? `Получено ${day(item.confirmed_at)}` : 'Впереди' }}</span>
            <span v-if="!item.is_required" class="small">Необязательно</span>
          </li>
        </ul>
      </section>
      <section class="card equipment">
        <h2>Ваши достижения</h2>
        <AchievementsShelf :achievements="journey.achievements" own />
      </section>
      <OnboardingHistory :entries="journey.history" />
      <!-- Финал маршрута: поздравление на месте, без модального окна —
           страницу открывают и после финиша, за результатами. -->
      <section v-if="journey.status === 'completed'" class="card congrats">
        <span class="tile__icon tile__icon--trophy congrats__mark" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
            <path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />
          </svg>
        </span>
        <div>
          <h2 class="congrats__title">
            Маршрут пройден!
          </h2>
          <p class="faint">
            {{ journey.settings.completed_message }}
          </p>
        </div>
      </section>

    </template>
  </section>
</template>

<style scoped>
.equipment { padding: 1.25rem; margin-bottom: 1.25rem; }
.equipment__icon { width: 30px; height: 30px; }
.equipment h2 { font-size: 1rem; margin-top: 0; }
.equipment__items { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 1rem; list-style: none; padding: 0; }
.equipment__items li { display: grid; gap: .5rem; padding: 1rem; background: var(--color-surface-sunken); border-radius: var(--radius); color: var(--color-text-muted); }
.equipment__items li.equipment__done { background: var(--color-success-soft); color: var(--color-success); }
/*
 * Шапка — единственное цветное пятно на экране: дальше идут белые карточки,
 * и акцент в них работает только на действиях.
 */
.hero {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1.5rem;
  flex-wrap: wrap;
  padding: 1.75rem 1.75rem 1.6rem;
  margin-bottom: 1.25rem;
  border-radius: var(--radius-lg);
  background: var(--color-highlight);
  color: var(--color-highlight-text);
}

.hero__body {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  min-width: 0;
}

.hero__eyebrow {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  opacity: 0.7;
}

.hero__title {
  margin: 0;
  font-size: clamp(1.6rem, 3.5vw, 2.1rem);
  line-height: 1.1;
}

.hero__lead {
  margin: 0;
  opacity: 0.85;
}

.hero__now {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.15rem;
}

.hero__day {
  padding: 0.35rem 0.9rem;
  border-radius: var(--radius-pill);
  background: rgba(var(--color-highlight-text-rgb), 0.14);
  font-weight: 600;
}

.hero__since {
  font-size: 0.8rem;
  opacity: 0.7;
}

/* ---------- Прогресс и цель ---------- */

.top {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr));
  gap: 1.25rem;
  margin-bottom: 1.25rem;
}

.tile {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.1rem 1.25rem;
}

.tile__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: var(--radius);
}

.tile__icon svg {
  width: 1.4rem;
  height: 1.4rem;
}

.tile__icon--trophy {
  background: var(--color-success-soft);
  color: var(--color-success);
}

.tile__icon--star {
  background: var(--color-accent-soft);
  color: var(--color-accent);
}

.tile__body {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  min-width: 0;
  flex: 1;
}

.tile__label {
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-faint);
}

.tile__goal {
  font-weight: 600;
}

.tile__percent {
  font-size: 1.5rem;
  font-weight: 700;
  line-height: 1;
}

.tile__go {
  flex: none;
  text-decoration: none;
}

.small {
  font-size: 0.82rem;
}

/* ---------- Карта и карточка ---------- */

.layout {
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(22rem, 1fr);
  gap: 1.25rem;
  align-items: start;
  margin-bottom: 1.25rem;
}

/* Карточка этапа держится в поле зрения, пока листают карту. */
.layout__detail {
  position: sticky;
  top: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

/* ---------- Финал ---------- */

.congrats {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.25rem 1.5rem;
  margin-bottom: 1.25rem;
}

.congrats__mark {
  flex: none;
}

.congrats__title {
  margin: 0;
  font-size: 1rem;
}

@media (max-width: 60rem) {
  .layout {
    grid-template-columns: minmax(0, 1fr);
  }

  .layout__detail {
    position: static;
  }
}
</style>
