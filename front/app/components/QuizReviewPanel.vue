<script setup lang="ts">
import type { QuizReview, QuizReviewQuestion } from '~/types/lms'

defineProps<{ review: QuizReview }>()

/**
 * Как показать вариант: выбран, верен, и то и другое.
 *
 * Пока ключ закрыт, «верно» неизвестно — вариант остаётся просто выбранным,
 * а неправоту вопроса человек видит по его заголовку.
 */
function optionClass(question: QuizReviewQuestion, isChosen: boolean, isCorrect: boolean | null) {
  return {
    'review-option--chosen': isChosen,
    'review-option--right': isCorrect === true,
    'review-option--wrong': isCorrect === false && isChosen,
    'review-option--missed': isCorrect === true && !isChosen && !question.is_correct,
  }
}
</script>

<template>
  <div class="review">
    <p v-if="!review.reveals_key" class="review__note">
      Верные ответы откроются, когда тест будет сдан или попытки закончатся.
      Сейчас видно, в каких вопросах ошибка, — к ним и стоит вернуться в уроке.
    </p>

    <ol class="review__list">
      <li v-for="question in review.questions" :key="question.id" class="review-question">
        <p class="review-question__text">
          <span
            class="review-question__mark"
            :class="question.is_correct ? 'review-question__mark--right' : 'review-question__mark--wrong'"
            aria-hidden="true"
          >{{ question.is_correct ? '✓' : '✕' }}</span>

          {{ question.text }}

          <span class="visually-hidden">{{ question.is_correct ? '— верно' : '— неверно' }}</span>
          <span v-if="!question.is_answered" class="badge">без ответа</span>
        </p>

        <ul class="review-question__options">
          <li
            v-for="option in question.options"
            :key="option.id"
            class="review-option"
            :class="optionClass(question, option.is_chosen, option.is_correct)"
          >
            <span class="review-option__text">{{ option.text }}</span>
            <span v-if="option.is_chosen" class="review-option__tag">ваш ответ</span>
            <span v-else-if="option.is_correct" class="review-option__tag">верный ответ</span>
          </li>
        </ul>
      </li>
    </ol>
  </div>
</template>

<style scoped>
.review {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.review__note {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.86rem;
}

.review__list {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.review-question__text {
  display: flex;
  align-items: baseline;
  gap: 0.45rem;
  margin: 0 0 0.4rem;
  font-size: 0.95rem;
}

.review-question__mark {
  font-weight: 600;
}

.review-question__mark--right {
  color: var(--color-success);
}

.review-question__mark--wrong {
  color: var(--color-danger);
}

.review-question__options {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.review-option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.6rem;
  border: 1px solid transparent;
  border-radius: var(--radius);
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.review-option--chosen {
  border-color: var(--color-border);
  color: var(--color-text);
}

/* Верный вариант — фоном, неверный выбранный — рамкой: цвет здесь несёт
   смысл, поэтому он не единственный признак. */
.review-option--right {
  background: var(--color-success-soft);
  color: var(--color-success);
}

.review-option--wrong {
  border-color: var(--color-danger);
  color: var(--color-danger);
}

.review-option--missed {
  border-style: dashed;
}

.review-option__tag {
  margin-left: auto;
  font-size: 0.78rem;
  white-space: nowrap;
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
