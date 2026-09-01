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
  /** Итог последней отправки. Null — вопросы ещё не отправляли. */
  result?: { score: number, passed: boolean, review?: QuizReview | null } | null
  /** Правило сдачи прописью: планка у документа и у новости разная. */
  rule: string
  /** Что зачтено сдачей: «Новость отмечена как прочитанная». */
  passedNote: string
  /** Куда вернуться, если не сдал: «Перечитайте документ и попробуйте снова». */
  failedNote: string
}>()

const emit = defineEmits<{
  submit: [answers: Record<number, number[]>]
  retry: []
}>()

const answers = ref<Record<number, number[]>>({})

const questions = computed(() => props.quiz.questions ?? [])

/**
 * Отправлять можно, когда отвечен каждый вопрос: пустой ответ — это не «не
 * знаю», а недоделанная работа, и попытку на него тратить незачем.
 */
const isAnswered = computed(
  () => questions.value.length > 0
    && questions.value.every(question => (answers.value[question.id] ?? []).length > 0),
)

function toggle(questionId: number, optionId: number, single: boolean) {
  const chosen = answers.value[questionId] ?? []

  answers.value = {
    ...answers.value,
    [questionId]: single
      ? [optionId]
      : chosen.includes(optionId) ? chosen.filter(id => id !== optionId) : [...chosen, optionId],
  }
}

function isChosen(questionId: number, optionId: number): boolean {
  return (answers.value[questionId] ?? []).includes(optionId)
}

function retry() {
  answers.value = {}
  emit('retry')
}
</script>

<template>
  <section class="card quiz">
    <header>
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

      <!-- Разбор сразу: пересдача без него учит разве что перебирать варианты. -->
      <QuizReviewPanel v-if="result.review" :review="result.review" />

      <button v-if="!result.passed" type="button" class="button-secondary" @click="retry">
        Попробовать снова
      </button>
    </template>

    <form v-else class="quiz__form" @submit.prevent="emit('submit', answers)">
      <fieldset v-for="(question, index) in questions" :key="question.id" class="question">
        <legend class="question__text">
          {{ index + 1 }}. {{ question.text }}
        </legend>

        <label v-for="option in question.options" :key="option.id" class="option">
          <input
            :type="question.type === 'single' ? 'radio' : 'checkbox'"
            :name="`question-${question.id}`"
            :checked="isChosen(question.id, option.id)"
            @change="toggle(question.id, option.id, question.type === 'single')"
          >
          {{ option.text }}
        </label>
      </fieldset>

      <button type="submit" class="button-primary" :disabled="isSubmitting || !isAnswered">
        {{ isSubmitting ? 'Отправляем…' : 'Отправить ответы' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
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
