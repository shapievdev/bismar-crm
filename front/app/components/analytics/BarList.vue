<script setup lang="ts">
import { formatCompactMoney, formatMoney, formatNumber } from '~/utils/numbers'

export interface BarRow {
  name: string
  value: number
  /** Вторая величина строки: маржа, число документов, доля просрочки. */
  meta?: string
  /** Доля выручки с известной себестоимостью — приглушает недостоверную маржу. */
  uncertain?: boolean
}

const props = withDefaults(defineProps<{
  rows: BarRow[]
  format?: 'money' | 'number'
}>(), {
  format: 'money',
})

/*
 * Рейтинг — сравнение величин, а не различение сущностей, поэтому цвет здесь
 * не кодирует ничего: длина полосы уже сказала всё. Лаймом отмечен только
 * первый — тот, ради которого рейтинг и открывают.
 */
const maximum = computed(() => Math.max(1, ...props.rows.map(row => Math.abs(row.value))))

function share(value: number): number {
  return (Math.abs(value) / maximum.value) * 100
}

function formatValue(value: number): string {
  return props.format === 'money' ? formatCompactMoney(value) : formatNumber(value)
}

function preciseValue(value: number): string {
  return props.format === 'money' ? formatMoney(value, 2) : formatNumber(value)
}

/** Лидер отмечается, только если он и правда впереди — а не один в списке. */
const leaderIndex = computed(() => (props.rows.length > 1 ? 0 : -1))
</script>

<template>
  <div v-if="rows.length" class="bars">
    <div v-for="(row, index) in rows" :key="row.name" class="bar">
      <div class="bar__head">
        <span class="bar__name" :title="row.name">{{ row.name }}</span>
        <span
          class="bar__value"
          :class="{ 'bar__value--leader': index === leaderIndex && row.value > 0 }"
          :title="preciseValue(row.value)"
        >{{ formatValue(row.value) }}</span>
      </div>

      <div class="bar__track">
        <div
          class="bar__fill"
          :class="{
            'bar__fill--leader': index === leaderIndex && row.value > 0,
            'bar__fill--negative': row.value < 0,
          }"
          :style="{ width: `${share(row.value)}%` }"
        />
      </div>

      <p v-if="row.meta" class="bar__meta" :class="{ 'bar__meta--uncertain': row.uncertain }">
        {{ row.meta }}
      </p>
    </div>
  </div>

  <p v-else class="bars__empty">
    За этот срез данных нет.
  </p>
</template>

<style scoped>
.bars {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}

.bar {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  min-width: 0;
}

.bar__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  font-size: 0.82rem;
}

.bar__name {
  /* Длинные названия товаров обрезаются: перенос на три строки ломает ряд. */
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}

.bar__value {
  flex-shrink: 0;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

/* Число лидера — той же лаймовой пилюлей, что пик на графиках. */
.bar__value--leader {
  padding: 0.15rem 0.5rem;
  border-radius: var(--radius-pill);
  background: var(--color-highlight);
  color: var(--color-highlight-text);
}

.bar__track {
  height: 10px;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  overflow: hidden;
}

.bar__fill {
  height: 100%;
  border-radius: var(--radius-pill);
  background: var(--color-text-muted);
  min-width: 6px;
}

.bar__fill--leader {
  background:
    repeating-linear-gradient(
      45deg,
      color-mix(in srgb, var(--color-highlight-text) 22%, transparent) 0 1px,
      transparent 1px 7px
    ),
    var(--color-highlight);
}

/* Убыток или возврат — противоположность, а не «мало»: другой полюс шкалы. */
.bar__fill--negative {
  background: var(--color-danger);
}

.bar__meta {
  margin: 0;
  font-size: 0.74rem;
  color: var(--color-text-muted);
}

/* Маржа, посчитанная по неполной себестоимости, не должна читаться как факт. */
.bar__meta--uncertain {
  color: var(--color-text-faint);
  font-style: italic;
}

.bars__empty {
  margin: 0;
  font-size: 0.85rem;
  color: var(--color-text-faint);
}
</style>