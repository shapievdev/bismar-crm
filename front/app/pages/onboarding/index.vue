<script setup lang="ts">
import type { FetchError } from 'ofetch'
import type { Quiz, QuizAttempt } from '~/types/lms'
import type { Journey } from '~/types/onboarding'

/**
 * Маршрут адаптации глазами сотрудника: приветствие, прогресс, карта,
 * карточка этапа и достижения.
 *
 * Все цифры приезжают с сервера готовыми; после каждого действия сервер
 * присылает журнал заново — экран никогда не считает прогресс сам.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Моя адаптация' })

const api = useOnboardingApi()
const route = useRoute()
const { user, can } = useAuth()
const previewEmployeeId = computed(() => {
  const id = Number(route.query.preview)
  return can('onboarding.manage') && Number.isSafeInteger(id) && id > 0 ? id : null
})
const isPreview = computed(() => previewEmployeeId.value !== null)
const isMobile = ref(false)
const detailOpen = ref(false)
onMounted(() => {
  const query = window.matchMedia('(max-width: 959px)')
  const update = () => { isMobile.value = query.matches; if (!query.matches) detailOpen.value = false }
  update()
  query.addEventListener('change', update)
  onBeforeUnmount(() => query.removeEventListener('change', update))
})

const { data, pending, error, refresh } = await useAsyncData(() => `onboarding.design.${previewEmployeeId.value ?? 'my'}`, () => previewEmployeeId.value ? api.fetchEmployeeJourney(previewEmployeeId.value) : api.fetchMy())

const journey = computed<Journey | null>(() => data.value?.data ?? null)

/** Имя без отчества: обращение, а не строка из кадров. */
const firstName = computed(() => {
  const full = journey.value?.employee.name ?? user.value?.name ?? ''

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

const submittingTaskId = ref<number | null>(null)
const actionError = ref<string | null>(null)

async function submitTask(taskId: number) {
  if (isPreview.value) return
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
  if (isPreview.value) return
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
  if (isPreview.value) return
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


function selectStage(id: number) {
  selectedId.value = id
  closeQuiz()
  if (isMobile.value) detailOpen.value = true
}

async function loadReview(id: number) {
  return (await (previewEmployeeId.value
    ? api.fetchEmployeeAttempt(previewEmployeeId.value, id)
    : api.fetchAttempt(id))).data.review ?? null
}

function day(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' }) : ''
}
</script>

<template>
  <section class="journey-play">
    <UiEmptyState
      v-if="error"
      title="Маршрута пока нет"
      description="Когда вам назначат адаптацию, карта появится здесь."
    />

    <div v-else-if="pending" class="card">
      <div class="skeleton skeleton-line" />
    </div>

    <template v-else-if="journey">
      <div v-if="isPreview" class="preview-note"><span>Просмотр маршрута · {{ journey.employee.name }}</span><span>Предпросмотр без выполнения заданий</span></div>
      <!-- Приветствие -->
      <header class="hero">
        <div class="hero__body">
          <span class="hero__eyebrow">Твоя новая глава · BISMAR</span>
          <h1 class="hero__title">
            {{ firstName ? `${firstName}, всё получится!` : 'Всё получится!' }}
          </h1>
          <p class="hero__lead">Осваивай новое, собирай достижения<br>и становись увереннее с каждым шагом.</p>
          <p class="hero__duration">
            <template v-if="journey.total_days">
              {{ journey.total_days }}
              {{ journey.total_days === 1 ? 'день' : (journey.total_days < 5 ? 'дня' : 'дней') }} —
              и ты станешь частью команды.
            </template>
            <template v-else>
              Шаг за шагом — и ты станешь частью команды.
            </template>
          </p>
        </div>

        <OnboardingGuide class="hero__guide" />
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
            <span class="tile__label">{{ journey.next_action ? 'Твоя следующая цель' : 'Как идут дела' }}</span>
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
          <button v-else-if="journey.next_action?.journey_stage_id" type="button" class="button-primary tile__go" @click="selectStage(journey.next_action.journey_stage_id)">К этапу</button>
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
          @select="selectStage"
        />

        <div v-if="!isMobile" class="layout__detail">
          <div class="detail-caption"><span>ТВОЯ МИССИЯ</span><span>✦</span></div>
          <OnboardingStageSession :stage="selectedStage" :next="nextStage" :quiz="quiz" :result="quizResult" :load-review="loadReview"
            :submitting-quiz="isSubmittingQuiz" :submitting-task-id="submittingTaskId"
            :interactive="journey.status === 'active' && !isPreview" :passed-message="journey.settings.passed_message" :failed-message="journey.settings.failed_message"
            @submit-task="submitTask" @open-quiz="openQuiz" @close-quiz="closeQuiz" @submit-quiz="submitQuiz" @retry="closeQuiz(); openQuiz()" />
        </div>
      </div>

      <AppSheet :open="isMobile && detailOpen" title="Твоя миссия" @close="detailOpen = false">
        <p v-if="actionError" class="alert alert--danger" role="alert">{{ actionError }}</p>
        <OnboardingStageSession :stage="selectedStage" :next="nextStage" :quiz="quiz" :result="quizResult" :load-review="loadReview"
            :submitting-quiz="isSubmittingQuiz" :submitting-task-id="submittingTaskId"
            :interactive="journey.status === 'active' && !isPreview" :passed-message="journey.settings.passed_message" :failed-message="journey.settings.failed_message"
            @submit-task="submitTask" @open-quiz="openQuiz" @close-quiz="closeQuiz" @submit-quiz="submitQuiz" @retry="closeQuiz(); openQuiz()" />
      </AppSheet>
      <section class="card equipment">
        <div class="section-heading"><div><span class="section-eyebrow">Каждый шаг имеет значение</span><h2>Твои маленькие победы</h2></div><span class="section-stamp">✦ {{ journey.achievements.length }}</span></div>
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
.journey-play { max-width:1200px; margin:0 auto; --play-radius:26px; }
.preview-note { display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:16px; padding:12px 16px; border:1px dashed var(--color-border-strong); border-radius:14px; font-size:11px; color:var(--color-text-muted); }
.hero { position:relative; isolation:isolate; min-height:230px; padding:32px; margin-bottom:22px; border-radius:30px; background:var(--color-highlight); color:var(--color-highlight-text); overflow:hidden; }
.hero::after { content:''; position:absolute; z-index:-1; width:300px;height:300px;border:50px solid currentColor; border-radius:50%; opacity:.035; right:-40px;top:-80px; }
.hero__body { max-width:68%; }
.hero__eyebrow { font-size:10px; font-weight:800; letter-spacing:.14em; text-transform:uppercase; opacity:.65; }
.hero__title { margin:13px 0 10px; font-size:clamp(28px,3vw,40px); font-weight:750; letter-spacing:-.035em; line-height:1.1; }
.hero__lead { font-size:15px; line-height:1.6; margin:0; opacity:.85; }
.hero__duration { margin:16px 0 0; font-size:11px; opacity:.65; }
.hero__guide { position:absolute; width:170px; height:185px; right:105px; bottom:8px; transform:rotate(5deg); }
.hero__now { position:absolute; top:26px;right:26px; display:grid;gap:5px;text-align:right; }
.hero__day { font-size:11px; font-weight:700; border:1px solid currentColor; border-radius:10px; padding:7px 12px; }
.hero__since { font-size:10px; opacity:.6; }
.top { display:grid; grid-template-columns:minmax(0,.9fr) minmax(0,1.3fr); gap:20px; margin-bottom:24px; }
.tile { display:flex; align-items:center; gap:16px; padding:20px; border:2px solid var(--color-border); border-radius:22px; box-shadow:0 4px 0 var(--color-border); }
.tile__icon { width:42px;height:42px;border-radius:14px;display:grid;place-items:center;flex:none;background:var(--control-surface); }
.tile__icon svg { width:23px;height:23px; }
.tile__icon--trophy { background:var(--color-success-soft);color:var(--color-success); }
.tile__icon--star { color:var(--color-text); background:var(--color-accent-soft); }
.tile__body { display:flex;flex-direction:column;gap:7px; min-width:0;flex:1; }
.tile__label { font-size:9px; letter-spacing:.09em;text-transform:uppercase;color:var(--color-text-muted);font-weight:700; }
.tile__goal { font-size:13px;line-height:1.4;font-weight:650; }
.tile__percent { font-size:27px;font-weight:750;letter-spacing:-.04em; }
.tile__go { background:var(--color-highlight);color:var(--color-highlight-text);font-size:12px;font-weight:750;padding:12px 18px;border-radius:14px;box-shadow:0 4px 0 var(--color-highlight-strong);flex:none;text-decoration:none; }
.small { font-size:10px;line-height:1.5; }
.layout { display:grid;grid-template-columns:minmax(0,1fr) 370px;gap:26px;align-items:start;margin-bottom:28px; }
.layout__detail { position:sticky;top:100px; min-width:0; }
.detail-caption { display:flex;justify-content:space-between;padding:0 6px 13px;font-size:10px;font-weight:750;letter-spacing:.11em;color:var(--color-text-muted); }
.detail-caption span:last-child { color:var(--color-highlight-strong);font-size:16px; }
.equipment { border:2px solid var(--color-border); border-radius:26px;padding:24px;margin-bottom:24px;box-shadow:0 4px 0 var(--color-border); }
.section-heading { display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px; }
.section-eyebrow { color:var(--color-text-muted);font-size:10px; }
.equipment h2 { font-size:21px;margin:6px 0 0;letter-spacing:-.02em;font-weight:700; }
.section-stamp { padding:10px 13px;border-radius:14px;border:2px solid var(--color-border);font-weight:700;white-space:nowrap; }
.congrats { display:flex;align-items:center;gap:20px;padding:30px;border:2px solid var(--color-success);border-radius:26px;background:var(--color-success-soft);margin-bottom:24px; }
.congrats__mark { color:var(--color-success);width:64px;height:64px; }
.congrats__mark svg { width:44px;height:44px; }
.congrats__title { margin:0 0 8px;font-size:26px;font-weight:750; }
@media(min-width:1400px) { .layout { grid-template-columns:minmax(0,1fr) 410px; } }
@media(max-width:959px) { .layout { grid-template-columns:minmax(0,1fr); } .layout__detail { display:none; } .top { grid-template-columns:minmax(0,1fr);gap:14px; } .hero__guide { right:65px;width:145px; } .hero__body { max-width:70%; } }
@media(max-width:600px) {
 .hero { padding:24px 20px 22px; min-height:250px;border-radius:24px; }
 .hero__body { max-width:100%; }
 .hero__eyebrow { font-size:9px; }
 .hero__title { max-width:75%;font-size:28px;margin:18px 0 12px; }
 .hero__lead { font-size:12px;max-width:75%;line-height:1.6; }
 .hero__duration { font-size:10px;max-width:70%;line-height:1.5; }
 .hero__guide { width:100px;height:112px;right:3px;bottom:8px; }
 .hero__now { top:18px;right:16px; }
 .hero__day { font-size:9px;padding:6px 8px; }
 .hero__since { display:none; }
 .tile { padding:16px;gap:12px; }
 .tile__icon { width:34px;height:34px; }
 .tile__percent { font-size:24px; }
 .tile__go { padding:11px 13px;font-size:11px; }
 .tile__goal { font-size:12px; }
 .equipment { padding:20px 16px;border-radius:24px; }
 .equipment h2 { font-size:18px; }
 .section-eyebrow { font-size:9px; }
 .congrats { padding:20px;gap:12px; }
 .congrats__title { font-size:21px; }
}
</style>
