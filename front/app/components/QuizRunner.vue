<script setup lang="ts">
import type { Quiz, QuizReview } from '~/types/lms'

/**
 * Проверка, которую проходят вместо кнопки «ознакомлен».
 *
 * Одна разметка на новость и на документ: там и там тест подтверждает, что
 * человек прочитал, и разница между ними только в словах о том, что именно
 * зачтено. У теста при уроке экран свой — там к вопросам добавляются прогресс,
 * очередь уроков и история попыток.
 *
 * Компонент ничего не решает сам: показывает вопросы и сообщает об отправке.
 * Кто и как оценивает — дело того, кто его поставил.
 */
const props = defineProps<{
  quiz: Quiz
  isSubmitting: boolean
  errorMessage?: string | null
  /**
   * Итог последней отправки. Null — вопросы ещё не отправляли.
   *
   * Разбор показывает, в каких вопросах ошибка, но не верные ответы: ключ
   * открывается только когда попытки кончились — см. QuizReview на сервере.
   */
  result?: { score: number, passed: boolean, review?: QuizReview | null } | null
  /** Правило сдачи прописью: планка у документа и у новости разная. */
  rule: string
  /** Что зачтено сдачей: «Новость отмечена как прочитанная». */
  passedNote: string
  /** Куда вернуться, если не сдал: «Перечитайте документ и попробуйте снова». */
  failedNote: string
  /**
   * Конец таймера — только у экзамена. Когда время выходит, компонент сам
   * говорит `expire` с тем, что успели ответить: страница отправляет это как
   * есть, и частичная работа честно оценивается вместо нулевого списания.
   */
  deadline?: string | null
  /**
   * Разрешить отправку недоделанного. У теста при материале кнопка ждёт
   * ответа на всё — попытку на пустой бланк тратить незачем. На экзамене с
   * таймером это правило душит: не успевающий должен отправить то, что есть,
   * а не смотреть на запертую кнопку.
   */
  allowPartial?: boolean
  /** A failed attempt can be retried only while attempts remain. */
  canRetry?: boolean
}>()

const emit = defineEmits<{
  submit: [answers: Record<number, number[] | string | string[][]>]
  retry: []
  expire: [answers: Record<number, number[] | string | string[][]>]
}>()

/**
 * Ответы: у вопроса с выбором — номера вариантов, у письменного — строка.
 * Уходят одним полем, как их и принимает сервер.
 */
const answers = ref<Record<number, number[] | string | string[][]>>({})

function written(questionId: number): string {
  const answer = answers.value[questionId]

  return typeof answer === 'string' ? answer : ''
}

/** Строки таблицы держит сам компонент таблицы — здесь только их место. */
function tableRows(questionId: number): string[][] {
  const answer = answers.value[questionId]

  return Array.isArray(answer) && Array.isArray(answer[0]) ? answer as string[][] : []
}

function setTableRows(questionId: number, rows: string[][]) {
  answers.value = { ...answers.value, [questionId]: rows }
}

function write(questionId: number, value: string) {
  answers.value = { ...answers.value, [questionId]: value }
}

const questions = computed(() => props.quiz.questions ?? [])

/** Отвечен ли вопрос — чем бы ни отвечали. */
function hasAnswer(question: { id: number }): boolean {
  const answer = answers.value[question.id]

  if (typeof answer === 'string') {
    return answer.trim() !== ''
  }

  // У таблицы «отвечено» — хоть одна заполненная ячейка: полнота её
  // проверяется на сервере, там же лежит и правило зачёта.
  if (Array.isArray(answer) && Array.isArray(answer[0])) {
    return (answer as string[][]).some(row => row.some(cell => cell.trim() !== ''))
  }

  return chosenOptions(question.id).length > 0
}

/**
 * Отправлять можно, когда отвечен каждый вопрос: пустой ответ — это не «не
 * знаю», а недоделанная работа, и попытку на него тратить незачем. На экзамене
 * с таймером хватает одного ответа — см. allowPartial.
 */
const isAnswered = computed(
  () => questions.value.length > 0
    && (props.allowPartial
      ? questions.value.some(hasAnswer)
      : questions.value.every(hasAnswer)),
)

/* ---------- Таймер экзамена ---------- */

/**
 * Осталось секунд. Null — таймера нет вовсе, и в разметке его не видно.
 *
 * Секунды считаются от часов браузера против присланного срока: часы могут
 * врать, но сервер всё равно перепроверит — у него на это своя поблажка в
 * минуту, см. ExamSitting::GRACE_SECONDS.
 */
const remainingSeconds = ref<number | null>(null)
let countdown: ReturnType<typeof setInterval> | null = null

function tick() {
  if (!props.deadline) {
    remainingSeconds.value = null

    return
  }

  const left = Math.max(0, Math.ceil((new Date(props.deadline).getTime() - Date.now()) / 1000))

  remainingSeconds.value = left

  if (left <= 0) {
    stopCountdown()
    emit('expire', answers.value)
  }
}

function stopCountdown() {
  if (countdown !== null) {
    clearInterval(countdown)
    countdown = null
  }
}

watch(() => props.deadline, (deadline) => {
  stopCountdown()

  if (deadline && import.meta.client) {
    tick()
    countdown = setInterval(tick, 1000)
  }
  else {
    remainingSeconds.value = null
  }
}, { immediate: true })

onBeforeUnmount(stopCountdown)

const remainingLabel = computed(() => {
  if (remainingSeconds.value === null) {
    return null
  }

  const minutes = Math.floor(remainingSeconds.value / 60)
  const seconds = remainingSeconds.value % 60

  return `${minutes}:${String(seconds).padStart(2, '0')}`
})

/** Выбранные варианты: у прочих видов ответа их нет. */
function chosenOptions(questionId: number): number[] {
  const answer = answers.value[questionId]

  return Array.isArray(answer) && !Array.isArray(answer[0]) ? answer as number[] : []
}

function toggle(questionId: number, optionId: number, single: boolean) {
  const chosen = chosenOptions(questionId)

  answers.value = {
    ...answers.value,
    [questionId]: single
      ? [optionId]
      : chosen.includes(optionId) ? chosen.filter(id => id !== optionId) : [...chosen, optionId],
  }
}

function isChosen(questionId: number, optionId: number): boolean {
  return chosenOptions(questionId).includes(optionId)
}

function retry() {
  answers.value = {}
  emit('retry')
}
</script>

<template>
  <section class="card quiz" data-material-test>
    <header>
      <!-- Таймер — у заголовка, а не в углу экрана: взгляд возвращается к
           вопросам, и время должно попадаться по пути. -->
      <p
        v-if="remainingLabel && !result"
        class="quiz__timer"
        :class="{ 'quiz__timer--low': (remainingSeconds ?? 0) <= 60 }"
        role="timer"
        aria-live="off"
      >
        Осталось {{ remainingLabel }}
      </p>

      <h2 class="quiz__title">
        {{ quiz.title }}
      </h2>
      <p class="faint">
        {{ rule }}
      </p>
      <p v-if="quiz.description" class="faint">
        {{ quiz.description }}
      </p>
    </header>

    <p v-if="errorMessage" class="alert alert--danger" role="alert">
      {{ errorMessage }}
    </p>

    <template v-if="result">
      <p class="alert" :class="result.passed ? 'alert--success' : 'alert--danger'" role="status">
        <template v-if="result.passed">
          Сдано, {{ result.score }}%. {{ passedNote }}
        </template>
        <template v-else>
          {{ result.score }}% — этого не хватило. {{ failedNote }}
        </template>
      </p>

      <!-- Разбор: где ошибка, видно сразу; верные ответы — нет. -->
      <QuizReviewPanel v-if="result.review" :review="result.review" />

      <button v-if="!result.passed && canRetry !== false" type="button" class="button-secondary" :disabled="isSubmitting" @click="retry">
        Попробовать снова
      </button>
    </template>

    <form v-else class="quiz__form" @submit.prevent="emit('submit', answers)">
      <fieldset v-for="(question, index) in questions" :key="question.id" class="question">
        <legend class="question__text">
          {{ index + 1 }}. {{ question.text }}
        </legend>

        <!-- Письменный ответ: своими словами, а верность проверит ИИ по
             схожести с эталоном автора. -->
        <textarea
          v-if="question.type === 'long_text'"
          class="input written"
          rows="4"
          :value="written(question.id)"
          placeholder="Ответьте своими словами"
          @input="write(question.id, ($event.target as HTMLTextAreaElement).value)"
        />
        <input
          v-else-if="question.type === 'text'"
          class="input written"
          type="text"
          :value="written(question.id)"
          placeholder="Ответьте одной строкой"
          @input="write(question.id, ($event.target as HTMLInputElement).value)"
        >

        <QuizTable
          v-else-if="question.type === 'table' && question.table"
          :table="question.table"
          :model-value="tableRows(question.id)"
          @update:model-value="rows => setTableRows(question.id, rows)"
        />

        <template v-else>
          <label v-for="option in question.options" :key="option.id" class="option">
            <input
              :type="question.type === 'single' ? 'radio' : 'checkbox'"
              :name="`question-${question.id}`"
              :checked="isChosen(question.id, option.id)"
              @change="toggle(question.id, option.id, question.type === 'single')"
            >
            {{ option.text }}
          </label>
        </template>
      </fieldset>

      <button type="submit" class="button-primary" :disabled="isSubmitting || !isAnswered">
        {{ isSubmitting ? 'Отправляем…' : 'Отправить ответы' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.written {
  width: 100%;
}

.quiz {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.4rem 1.5rem;
  margin-bottom: 1.5rem;
}

.quiz__title {
  margin: 0;
  font-size: 1.15rem;
}

.quiz__timer {
  margin: 0 0 0.35rem;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

/* Последняя минута — тревожным цветом: на неё смотрят, а не читают. */
.quiz__timer--low {
  color: var(--color-danger);
}

.faint {
  margin: 0.25rem 0 0;
}

.quiz__form {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  align-items: flex-start;
}

.question {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  margin: 0;
  padding: 0;
  border: 0;
}

.question__text {
  padding: 0;
  font-weight: 500;
}

.option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
}
</style>
