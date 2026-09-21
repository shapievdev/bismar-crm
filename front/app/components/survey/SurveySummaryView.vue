<script setup lang="ts">
import type { SurveyQuestionSummary, SurveySummary } from '~/types/survey'

/**
 * Сводка опроса — только показ.
 *
 * Отделено от загрузки намеренно: сводку показывают в двух местах с разными
 * привычками. Редактор материала спрашивает её сам, когда его открыли
 * (SurveySummaryPanel), а отчёт аналитики получает её вместе со списком
 * прошедших одним ответом — и просить у сервера то же второй раз ему незачем.
 *
 * Разметка при этом одна: два списка ответов разошлись бы в первый же день,
 * когда у вопроса появится третья строка.
 */
defineProps<{ summary: SurveySummary }>()

/** Доля деления среди ответивших — ею рисуется столбик шкалы. */
function scaleShare(question: SurveyQuestionSummary, count: number): number {
  const total = Math.max(...(question.scale?.steps ?? []).map(step => step.count), 1)

  return Math.round(count / total * 100)
}
</script>

<template>
  <section class="summary">
    <header class="summary__head">
      <p class="summary__count">
        Прошли: <b>{{ summary.answered }}</b>
      </p>

      <ul class="summary__marks">
        <li v-if="summary.is_required" class="mark mark--required">Обязательный</li>
        <li v-if="summary.is_anonymous" class="mark">Анонимный</li>
        <li v-if="!summary.is_open" class="mark">Закрыт</li>
      </ul>
    </header>

    <p v-if="summary.is_anonymous" class="faint summary__note">
      Опрос анонимный: имён здесь нет и не будет — ответы не связаны с людьми в
      самой базе. Видно только, кто опрос прошёл.
    </p>

    <p v-if="summary.answered === 0" class="faint summary__note">
      Пока никто не ответил.
    </p>

    <article v-for="question in summary.questions" :key="question.id" class="question">
      <header class="question__head">
        <h4 class="question__text">{{ question.text }}</h4>
        <span class="faint question__answered">{{ question.answered }} ответ(ов)</span>
      </header>

      <!-- Выбор: сколько за какой вариант, полосой и числом. -->
      <ul v-if="question.options?.length" class="bars">
        <li v-for="option in question.options" :key="option.id" class="bar">
          <span class="bar__label">{{ option.text }}</span>
          <span class="bar__track">
            <span class="bar__fill" :style="{ width: `${option.share}%` }" />
          </span>
          <span class="bar__value">{{ option.count }} · {{ option.share }}%</span>
        </li>
      </ul>

      <!-- Шкала: распределение по делениям и среднее. -->
      <template v-if="question.scale">
        <p class="scale__average">
          Среднее: <b>{{ question.scale.average ?? '—' }}</b>
        </p>

        <ul class="bars">
          <li v-for="step in question.scale.steps" :key="step.value" class="bar">
            <span class="bar__label bar__label--step">{{ step.value }}</span>
            <span class="bar__track">
              <span class="bar__fill" :style="{ width: `${scaleShare(question, step.count)}%` }" />
            </span>
            <span class="bar__value">{{ step.count }}</span>
          </li>
        </ul>

        <p v-if="question.scale.min_label || question.scale.max_label" class="faint scale__labels">
          1 — {{ question.scale.min_label || '—' }}, {{ question.scale.steps.at(-1)?.value }} —
          {{ question.scale.max_label || '—' }}
        </p>
      </template>

      <!-- Свои варианты и письменные ответы — целиком, как написаны. -->
      <ul v-if="question.other?.length || question.texts?.length" class="said">
        <li v-for="(row, index) in [...(question.other ?? []), ...(question.texts ?? [])]" :key="index" class="said__row">
          <p class="said__text">{{ row.text }}</p>
          <p v-if="row.person" class="faint said__person">{{ row.person }}</p>
        </li>
      </ul>
    </article>
  </section>
</template>

<style scoped>
.summary {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}

.summary__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.summary__count {
  margin: 0;
  font-size: 0.95rem;
}

.summary__marks {
  display: flex;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.mark {
  padding: 0.15rem 0.55rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  font-size: 0.74rem;
}

.mark--required {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.summary__note {
  margin: 0;
  font-size: 0.84rem;
  line-height: 1.5;
}

.question {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--color-border);
}

.question__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
}

.question__text {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 500;
}

.question__answered {
  flex-shrink: 0;
  font-size: 0.78rem;
}

.bars {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

/*
 * Подпись, полоса и число — тремя колонками: так полосы начинаются на одной
 * вертикали и их длину можно сравнить глазом, а не читая проценты.
 */
.bar {
  display: grid;
  grid-template-columns: minmax(6rem, 12rem) minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.85rem;
}

.bar__label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.bar__label--step {
  font-variant-numeric: tabular-nums;
}

.bar__track {
  height: 0.5rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  overflow: hidden;
}

.bar__fill {
  display: block;
  height: 100%;
  border-radius: var(--radius-pill);
  background: var(--color-accent);
}

.bar__value {
  flex-shrink: 0;
  font-size: 0.78rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-text-muted);
}

.scale__average,
.scale__labels {
  margin: 0;
  font-size: 0.84rem;
}

.said {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.said__row {
  padding: 0.5rem 0.65rem;
  border-radius: var(--radius-sm);
  background: var(--color-surface);
}

.said__text {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.5;
  white-space: pre-line;
}

.said__person {
  margin: 0.2rem 0 0;
  font-size: 0.78rem;
}

@media (max-width: 40rem) {
  /* На телефоне подпись встаёт над полосой: в шесть сантиметров ширины три
     колонки превращаются в три обрезанных слова. */
  .bar {
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .bar__label {
    grid-column: 1 / -1;
    white-space: normal;
  }
}
</style>
