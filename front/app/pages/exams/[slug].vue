<script setup lang="ts">
import type { FetchError } from 'ofetch'
import type { Quiz, QuizAttempt } from '~/types/lms'
import type { ExamWindow } from '~/types/exams'

/**
 * Сдача экзамена.
 *
 * До «Начать» — только шапка: вопросы приезжают с началом попытки, иначе
 * таймер мерил бы скорость набора заранее прочитанного. С начала попытки
 * тикает таймер (QuizRunner), и когда он выходит, страница отправляет то, что
 * успели, — у сервера на дорогу есть минутная поблажка.
 */
definePageMeta({ middleware: 'auth' })

const route = useRoute()
const slug = computed(() => String(route.params.slug))

const { fetchExam, startExam, submitExam } = useExamsApi()

const { data, pending, error, refresh } = await useAsyncData(
  `exams.${slug.value}`,
  () => fetchExam(slug.value),
)

const assigned = computed(() => data.value?.data ?? null)
const exam = computed(() => assigned.value?.exam ?? null)

useHead({ title: () => exam.value?.title ?? 'Экзамен' })

/* ---------- Попытка ---------- */

const quiz = ref<Quiz | null>(null)
const window_ = ref<ExamWindow | null>(null)
const result = ref<QuizAttempt | null>(null)

const isStarting = ref(false)
const isSubmitting = ref(false)
const actionError = ref<string | null>(null)

const isRunning = computed(() => quiz.value !== null && result.value === null)

/** Ответы уходят один раз: после expire не должно уйти второй отправкой. */
let hasSubmitted = false

async function start() {
  isStarting.value = true
  actionError.value = null

  try {
    const { window: openedWindow, quiz: questions } = await startExam(slug.value)

    window_.value = openedWindow
    quiz.value = questions
    result.value = null
    hasSubmitted = false
  }
  catch (caught) {
    actionError.value = (caught as FetchError<{ message?: string }>).data?.message
      ?? 'Не удалось начать экзамен.'
  }
  finally {
    isStarting.value = false
  }
}

async function submit(answers: Record<number, number[] | string | string[][]>) {
  if (hasSubmitted) {
    return
  }

  hasSubmitted = true
  isSubmitting.value = true
  actionError.value = null

  try {
    result.value = (await submitExam(slug.value, answers)).data
    quiz.value = null
    window_.value = null

    // Сводка в шапке («сдан», попытки) считается сервером — обновить её
    // дешевле, чем повторять его правила здесь.
    await refresh()
  }
  catch (caught) {
    hasSubmitted = false
    actionError.value = (caught as FetchError<{ message?: string }>).data?.message
      ?? 'Не удалось отправить работу.'

    // Отказ по времени — попытка уже списана; форма с истёкшим таймером
    // больше не нужна.
    if ((caught as FetchError).statusCode === 409) {
      quiz.value = null
      window_.value = null
      await refresh()
    }
  }
  finally {
    isSubmitting.value = false
  }
}

/** Время вышло: отправляем, что успели, — сервер оценит честно. */
function expire(answers: Record<number, number[] | string | string[][]>) {
  void submit(answers)
}

const summary = computed(() => assigned.value?.summary ?? null)

/** Идущая попытка переживает перезагрузку: окно хранит сервер. */
const canContinue = computed(() => assigned.value?.window != null && !isRunning.value && !result.value)

const canStart = computed(() => {
  if (!exam.value || !summary.value) {
    return false
  }

  return exam.value.is_open
    && !summary.value.awaiting_review
    && (exam.value.quiz?.max_attempts == null
      || summary.value.attempts_spent < exam.value.quiz.max_attempts)
})

const attemptsLabel = computed(() => {
  const max = exam.value?.quiz?.max_attempts
  const spent = summary.value?.attempts_spent ?? 0

  return max == null ? `Попыток потрачено: ${spent}` : `Попытки: ${spent} из ${max}`
})

function day(value: string | null): string {
  return value
    ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
    : '—'
}
</script>

<template>
  <section>
    <p v-if="error" class="alert alert--danger" role="alert">
      Экзамен не найден или вам его не назначали.
    </p>

    <div v-else-if="pending" class="card">
      <div class="skeleton skeleton-line" />
    </div>

    <template v-else-if="exam">
      <header class="head">
        <NuxtLink to="/exams" class="faint back">
          ← Экзамены
        </NuxtLink>
        <h1 class="page-title">
          {{ exam.title }}
        </h1>
        <p v-if="exam.description" class="page-subtitle">
          {{ exam.description }}
        </p>
        <p class="faint">
          {{ attemptsLabel }}
          <template v-if="exam.time_limit_minutes"> · {{ exam.time_limit_minutes }} мин на попытку</template>
          <template v-if="assigned?.due_at"> · сдать до {{ day(assigned.due_at) }}</template>
        </p>
      </header>

      <p v-if="actionError" class="alert alert--danger" role="alert">
        {{ actionError }}
      </p>

      <!-- Итог только что сданной работы: оценка и разбор. -->
      <template v-if="result">
        <p class="alert" :class="result.passed ? 'alert--success' : 'alert--danger'" role="status">
          <template v-if="result.review_status === 'pending'">
            Работа отправлена проверяющему — ответ придёт уведомлением.
          </template>
          <template v-else-if="result.passed">
            Сдано, {{ result.score }}%.
          </template>
          <template v-else>
            {{ result.score }}% — этого не хватило.
          </template>
        </p>

        <QuizReviewPanel v-if="result.review" :review="result.review" />
      </template>

      <!-- Идущая попытка: вопросы и таймер. -->
      <QuizRunner
        v-if="isRunning && quiz"
        :quiz="quiz"
        :is-submitting="isSubmitting"
        :deadline="window_?.expires_at ?? null"
        :allow-partial="window_?.expires_at !== null"
        :result="null"
        rule=""
        passed-note=""
        failed-note=""
        @submit="submit"
        @expire="expire"
      />

      <!-- Покой: ни попытки, ни свежего итога — только состояние и кнопка. -->
      <div v-else-if="!result" class="card state">
        <template v-if="summary?.passed">
          <p class="alert alert--success" role="status">
            Экзамен сдан — лучшая попытка {{ summary.best_score }}%.
          </p>
        </template>

        <template v-else-if="summary?.awaiting_review">
          <p class="alert" role="status">
            Работа у проверяющего. Ответ придёт уведомлением — сдавать заново пока нельзя.
          </p>
        </template>

        <template v-else>
          <p v-if="exam.closed_reason" class="alert" role="status">
            {{ exam.closed_reason }}
          </p>
          <p v-else-if="summary?.overdue" class="alert alert--danger" role="status">
            Срок прошёл, но окно сдачи ещё открыто — сдайте сейчас.
          </p>

          <p v-if="exam.time_limit_minutes && (canStart || canContinue)" class="faint">
            На попытку даётся {{ exam.time_limit_minutes }} мин — таймер пойдёт сразу после начала
            и не остановится, даже если закрыть страницу.
          </p>

          <button
            v-if="canContinue"
            type="button"
            class="button-primary"
            :disabled="isStarting"
            @click="start"
          >
            {{ isStarting ? 'Открываем…' : 'Продолжить попытку' }}
          </button>
          <button
            v-else-if="canStart"
            type="button"
            class="button-primary"
            :disabled="isStarting"
            @click="start"
          >
            {{ isStarting ? 'Открываем…' : 'Начать экзамен' }}
          </button>
        </template>
      </div>
    </template>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.5rem;
}

.back {
  display: inline-block;
  margin-bottom: 0.75rem;
  text-decoration: none;
}

.state {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 1rem;
  padding: 1.4rem 1.5rem;
}

.state .alert {
  width: 100%;
}
</style>
