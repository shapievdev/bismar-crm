<script setup lang="ts">
import type { QuizAttempt, QuizReview } from '~/types/lms'

/**
 * Свои попытки теста и разбор каждой.
 *
 * Одна разметка на урок и на документ: попытки у них устроены одинаково, а
 * разбор лежит по одному адресу — `quiz-attempts/{id}`.
 *
 * Разбор спрашивается по запросу и по одной попытке за раз: к нему возвращаются
 * (сдал с третьего раза, через месяц перечитываешь и хочешь вспомнить, что
 * тогда понял неверно), но простыня из четырёх разборов подряд не нужна никому.
 * Верные ответы в нём открываются по правилам самого теста — сдал или попытки
 * кончились, см. QuizReview на сервере.
 */
const props = defineProps<{
  attempts: QuizAttempt[]
  /** Подпись сворачиваемого блока: «Мои попытки (3)». */
  label?: string
  /**
   * Чем брать разбор. По умолчанию — своей попытки; автору материала чужие
   * отдаёт другой адрес, и приходит он отсюда: сам компонент о том, кто перед
   * ним, знать не должен.
   */
  loadReview?: (attemptId: number) => Promise<QuizReview | null>
}>()

const { fetchAttempt } = useLmsApi()

const openedId = ref<number | null>(null)
const review = ref<QuizReview | null>(null)
const isLoading = ref(false)
const errorMessage = ref<string | null>(null)

const summary = computed(() => `${props.label ?? 'Мои попытки'} (${props.attempts.length})`)

async function open(id: number) {
  if (openedId.value === id) {
    openedId.value = null
    review.value = null

    return
  }

  openedId.value = id
  review.value = null
  errorMessage.value = null
  isLoading.value = true

  try {
    review.value = props.loadReview
      ? await props.loadReview(id)
      : (await fetchAttempt(id)).data.review ?? null
  }
  catch {
    errorMessage.value = 'Не удалось показать разбор попытки.'
    openedId.value = null
  }
  finally {
    isLoading.value = false
  }
}

function when(value: string | null): string {
  return value ? new Date(value).toLocaleString('ru-RU') : ''
}
</script>

<template>
  <details v-if="attempts.length" class="history">
    <summary>{{ summary }}</summary>

    <p v-if="errorMessage" class="alert alert--danger" role="alert">
      {{ errorMessage }}
    </p>

    <ul>
      <li v-for="past in attempts" :key="past.id">
        <button
          type="button"
          class="history__row"
          :aria-expanded="openedId === past.id"
          @click="open(past.id)"
        >
          <span :class="past.passed ? 'pass' : 'fail'">{{ past.passed ? 'сдано' : 'не сдано' }}</span>
          <span>{{ past.score }}%</span>
          <span class="faint">{{ when(past.completed_at) }}</span>
          <span class="faint history__toggle">
            {{ openedId === past.id ? 'скрыть разбор' : 'разбор' }}
          </span>
        </button>

        <p v-if="openedId === past.id && isLoading" class="faint">
          Загружаем разбор…
        </p>
        <QuizReviewPanel
          v-else-if="openedId === past.id && review"
          :review="review"
          class="history__review"
        />
      </li>
    </ul>
  </details>
</template>

<style scoped>
.history {
  margin-top: 1.5rem;
  font-size: 0.87rem;
}

.history summary {
  cursor: pointer;
  color: var(--color-text-muted);
}

.history ul {
  margin: 0.6rem 0 0;
  padding: 0;
  list-style: none;
}

/* Строка попытки и её разбор — одним столбцом: разбор раскрывается под той
   строкой, к которой относится. */
.history li {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.3rem 0;
}

.history__row {
  display: flex;
  align-items: baseline;
  gap: 0.9rem;
  width: 100%;
  padding: 0.2rem 0;
  border: none;
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.history__toggle {
  margin-left: auto;
  text-decoration: underline;
  text-underline-offset: 0.2em;
}

.history__review {
  padding-left: 0.2rem;
}

.pass { color: var(--color-success); }
.fail { color: var(--color-danger); }
</style>
