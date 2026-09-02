<script setup lang="ts">
import type { QuizReview, QuizReviewQuestion } from '~/types/lms'

defineProps<{ review: QuizReview }>()

/**
 * Разошедшиеся ячейки с ожидаемым значением.
 *
 * Пусто, пока эталон закрыт: в разборе он появляется по тем же правилам, что и
 * ключ у выбора, — когда попытки кончились.
 */
function mismatches(question: QuizReviewQuestion) {
  const table = question.table

  if (!table) {
    return []
  }

  // Ведущая ячейка в ответе есть не всегда: когда её заполняет сотрудник,
  // столбцы сдвинуты на один.
  const hasLeading = table.row_label_title !== null
    && (table.rows.some(row => row.label === '') || table.can_add_rows)

  return (question.wrong_cells ?? []).flatMap((cell) => {
    const column = cell.cell - (hasLeading ? 1 : 0)
    const expected = table.rows[cell.row]?.expected?.[column]

    return expected
      ? [{
          row: cell.row,
          column,
          row_label: table.rows[cell.row]?.label || `Строка ${cell.row + 1}`,
          column_label: table.columns[column]?.title || `Столбец ${column + 1}`,
          expected,
        }]
      : []
  })
}

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
      Верные ответы откроются, когда закончатся попытки. Сейчас видно, в каких
      вопросах ошибка, — к ним и стоит вернуться в материале.
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

        <!-- Письменный ответ: своё написанное человек видит всегда, схожесть с
             эталоном — тоже («не зачтено» без числа выглядит произволом), а сам
             эталон открывается по тем же правилам, что и ключ у выбора. -->
        <template v-if="question.answer !== undefined && question.options.length === 0">
          <p class="written">
            <span class="written__label">Ваш ответ</span>
            {{ question.answer ?? '—' }}
          </p>

          <p v-if="question.similarity !== null && question.similarity !== undefined" class="written__score">
            Схожесть с эталоном — {{ Math.round(question.similarity * 100) }}%
            <template v-if="question.threshold">
              (зачитывается от {{ Math.round(question.threshold * 100) }}%)
            </template>
            <template v-if="question.measured_by === 'words'">
              · измерено пересечением слов: разбор по смыслу был недоступен
            </template>
          </p>

          <p v-if="question.expected_answer" class="written">
            <span class="written__label">Как правильно</span>
            {{ question.expected_answer }}
          </p>
        </template>

        <!-- Таблица: показывается как заполнена, скрывать в ней нечего —
             ключа у таблицы нет, зачёт идёт по заполненности. -->
        <template v-else-if="question.table">
          <QuizTable
            :table="question.table"
            :model-value="question.table_answer ?? []"
            :wrong="question.wrong_cells ?? []"
            disabled
          />

          <p v-if="question.required_cells" class="written__score">
            Заполнено {{ question.filled_cells ?? 0 }} из {{ question.required_cells }}
            {{ pluralise(question.required_cells, 'ячейки', 'ячеек', 'ячеек') }}
            <template v-if="question.checked_cells">
              · совпало с правильным ответом {{ question.correct_cells ?? 0 }}
              из {{ question.checked_cells }}
            </template>
          </p>

          <!-- Что именно ожидалось: показывается по тем же правилам, что и
               ключ у выбора — когда попытки кончились. -->
          <ul v-if="mismatches(question).length" class="mismatches">
            <li v-for="cell in mismatches(question)" :key="`${cell.row}:${cell.column}`">
              {{ cell.row_label }} · {{ cell.column_label }} — ожидалось «{{ cell.expected }}»
            </li>
          </ul>
        </template>

        <ul v-else class="review-question__options">
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
.mismatches {
  margin: 0.3rem 0 0;
  padding-left: 1.1rem;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.written {
  margin: 0.35rem 0 0;
  padding: 0.5rem 0.7rem;
  border-radius: var(--radius-sm);
  background: var(--color-surface-sunken);
  font-size: 0.92rem;
  white-space: pre-line;
}

.written__label {
  display: block;
  color: var(--color-text-muted);
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.written__score {
  margin: 0.3rem 0 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

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
