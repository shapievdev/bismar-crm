<script setup lang="ts">
import { changeAgainst, formatCompactMoney, formatNumber, formatPercent } from '~/utils/numbers'

/**
 * Одна цифра с тем, как она изменилась.
 *
 * Плитка, а не столбик из одного столбца: у одиночного значения нет ни ряда,
 * ни шкалы, и рисовать под него график — это украшать число рамкой.
 *
 * Рост не всегда хорош: у возвратов и просрочки «+18%» — плохая новость.
 * Поэтому направление задаётся отдельно от знака, а не выводится из него.
 */
const props = withDefaults(defineProps<{
  label: string
  value: number
  previous?: number
  format?: 'money' | 'number' | 'percent' | 'decimal'
  /** Что означает рост: хорошо (выручка) или плохо (возвраты, просрочка). */
  growth?: 'good' | 'bad' | 'neutral'
  hint?: string
  /** Точное значение в подсказке — когда в плитке оно сокращено. */
  precise?: string
  /**
   * Ширина в колонках той же сетки, в которой лежат панели с графиками.
   *
   * Плитка живёт среди них, а не в отдельной полосе сверху: сводное число —
   * такой же элемент дашборда, как график, и собственная сетка над общей давала
   * бы второй шаг и второй ритм на одном экране.
   */
  span?: number
}>(), {
  format: 'number',
  growth: 'good',
  span: 2,
})

const wide = computed(() => Math.min(12, Math.max(1, Math.round(props.span))))
const medium = computed(() => Math.min(6, Math.max(2, Math.ceil(wide.value / 2))))

const formatted = computed(() => {
  if (props.format === 'money') {
    return formatCompactMoney(props.value)
  }

  if (props.format === 'percent') {
    return formatPercent(props.value)
  }

  // Величины вроде «позиций в чеке» целыми не бывают: 2.93 и 3 — разные факты.
  if (props.format === 'decimal') {
    return formatNumber(props.value, 2)
  }

  return formatNumber(props.value)
})

const change = computed(() =>
  props.previous === undefined ? null : changeAgainst(props.value, props.previous),
)

/**
 * Цвет стрелки. Нейтральная метрика не красится вовсе: зелёная стрелка у
 * «числа складов» сообщала бы одобрение там, где его нет.
 */
const tone = computed(() => {
  const delta = change.value

  if (delta === null || delta === 0 || props.growth === 'neutral') {
    return 'flat'
  }

  const isGood = props.growth === 'good' ? delta > 0 : delta < 0

  return isGood ? 'good' : 'bad'
})
</script>

<template>
  <div
    class="tile"
    :title="precise"
    :style="{ '--span-wide': wide, '--span-medium': medium }"
  >
    <p class="tile__label">
      {{ label }}
    </p>

    <p class="tile__value">
      {{ formatted }}
    </p>

    <p v-if="change !== null" class="tile__change" :class="`tile__change--${tone}`">
      <span aria-hidden="true">{{ change > 0 ? '↑' : change < 0 ? '↓' : '→' }}</span>
      {{ formatPercent(Math.abs(change)) }}
      <span class="tile__against">к прошлому периоду</span>
    </p>

    <p v-else-if="hint" class="tile__hint">
      {{ hint }}
    </p>
  </div>
</template>

<style scoped>
.tile {
  display: flex;
  flex-direction: column;
  /* Подпись сверху, число под ней, изменение прижато к низу: в ряду плиток
     глаз идёт по числам, и они обязаны стоять на одной высоте. */
  justify-content: center;
  gap: 0.35rem;
  padding: 1.1rem 1.25rem;
  background: var(--color-surface-raised);
  border-radius: var(--radius);
  box-shadow: var(--shadow-sm);
  min-width: 0;

  grid-column: span var(--span-wide, 2);
  grid-row: span 1;
}

@media (max-width: 68rem) {
  .tile {
    grid-column: span var(--span-medium, 2);
  }
}

/* Телефон: плитки парами. Числа коротки, и по одной в строке они растянули бы
   страницу до того, что до первого графика пришлось бы прокручивать экран. */
@media (max-width: 40rem) {
  .tile {
    grid-column: span 1;
  }
}

.tile__label {
  margin: 0;
  font-size: 0.78rem;
  color: var(--color-text-muted);
  /* Подпись не переносится на две строки в узкой плитке — обрезается. */
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.tile__value {
  margin: 0;
  font-size: 1.55rem;
  font-weight: 600;
  letter-spacing: -0.02em;
  line-height: 1.15;
  /* Цифры одной ширины: иначе соседние плитки пляшут по горизонтали. */
  font-variant-numeric: tabular-nums;
}

.tile__change,
.tile__hint {
  margin: 0;
  font-size: 0.78rem;
  display: flex;
  align-items: baseline;
  gap: 0.3rem;
  flex-wrap: wrap;
}

.tile__hint {
  color: var(--color-text-faint);
}

.tile__change--good {
  color: var(--color-success);
}

.tile__change--bad {
  color: var(--color-danger);
}

.tile__change--flat {
  color: var(--color-text-faint);
}

.tile__against {
  color: var(--color-text-faint);
}

@media (max-width: 40rem) {
  .tile__value {
    font-size: 1.35rem;
  }

  .tile__against {
    display: none;
  }
}
</style>