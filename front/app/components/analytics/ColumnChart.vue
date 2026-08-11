<script setup lang="ts">
import { formatCompact, formatCompactMoney, formatMoney, formatNumber } from '~/utils/numbers'

export interface Column {
  label: string
  value: number
  /** Строка подсказки: то, чего нет в самой высоте столбца. */
  detail?: string
}

const props = withDefaults(defineProps<{
  columns: Column[]
  format?: 'money' | 'number'
  height?: number
  /** Подписывать каждый столбец, а не только пиковый (для семи дней недели). */
  labelAll?: boolean
}>(), {
  format: 'money',
  height: 200,
  labelAll: false,
})

/*
 * Столбцы, а не линия: часы суток и дни недели — отдельные корзины, а не
 * непрерывная величина. Линия между ними обещала бы промежуточные значения,
 * которых не существует.
 *
 * Все столбцы одинаково нейтральны, кроме одного. Раскрашивать их по величине
 * незачем — высота уже сказала всё, что цвет мог бы повторить; выделен только
 * пик, и выделен фактурой со штриховкой, а не оттенком.
 */
const maximum = computed(() => Math.max(1, ...props.columns.map(column => column.value)))

const hovered = ref<number | null>(null)

function share(value: number): number {
  return (value / maximum.value) * 100
}

function formatValue(value: number): string {
  return props.format === 'money' ? formatMoney(value) : formatNumber(value)
}

function formatShort(value: number): string {
  return props.format === 'money' ? formatCompactMoney(value) : formatCompact(value)
}

/** Пик подписывается всегда: ради него график и смотрят. */
const peakIndex = computed(() => {
  let peak = 0

  props.columns.forEach((column, index) => {
    if (column.value > (props.columns[peak]?.value ?? 0)) {
      peak = index
    }
  })

  return peak
})
</script>

<template>
  <div class="columns" :style="{ '--plot-height': `${height}px` }">
    <div class="columns__row">
      <div
        v-for="(column, index) in columns"
        :key="column.label"
        class="column"
        @mouseenter="hovered = index"
        @mouseleave="hovered = null"
      >
        <span
          v-if="(labelAll || index === peakIndex) && column.value > 0"
          class="column__value"
          :class="{ 'column__value--peak': index === peakIndex }"
        >{{ formatShort(column.value) }}</span>

        <div class="column__track">
          <div
            class="column__fill"
            :class="{
              'column__fill--peak': index === peakIndex && column.value > 0,
              'column__fill--empty': column.value === 0,
            }"
            :style="{ height: `${share(column.value)}%` }"
          />
        </div>

        <span class="column__label">{{ column.label }}</span>

        <div v-if="hovered === index" class="column__tip">
          <p class="column__tip-title">
            {{ column.label }}
          </p>
          <p class="column__tip-value">
            {{ formatValue(column.value) }}
          </p>
          <p v-if="column.detail" class="column__tip-detail">
            {{ column.detail }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.columns {
  min-width: 0;
}

.columns__row {
  display: flex;
  align-items: flex-end;
  /* Промежуток тоном поверхности: соседние столбцы не должны сливаться в
     сплошную заливку. */
  gap: 3px;
  min-width: 0;
}

.column {
  position: relative;
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
}

.column__track {
  display: flex;
  align-items: flex-end;
  width: 100%;
  height: var(--plot-height);
}

.column__fill {
  width: 100%;
  background: var(--color-surface-sunken);
  /*
   * Скругление во всю ширину столбца — форма, а не отделка: столбец читается
   * как мягкая колонна, и ряд перестаёт напоминать частокол. Низ оставлен
   * прямым: столбец растёт от базовой линии, и там у него опора.
   */
  border-radius: var(--radius) var(--radius) 0 0;
  min-height: 3px;
}

/*
 * Пик — той же штриховкой, что площадь на линейных графиках. Одна фактура
 * значит одно и то же во всём разделе: «вот эта величина».
 */
.column__fill--peak {
  background:
    repeating-linear-gradient(
      45deg,
      var(--color-border-strong) 0 1px,
      transparent 1px 7px
    ),
    var(--color-surface-sunken);
}

.column__fill--empty {
  background: var(--color-border);
  opacity: 0.5;
}

.column__value {
  font-size: 0.68rem;
  font-weight: 600;
  color: var(--color-text-muted);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

/* Число над пиком — лаймовой пилюлей, как выноска на линейных графиках. */
.column__value--peak {
  padding: 0.15rem 0.5rem;
  border-radius: var(--radius-pill);
  background: var(--color-highlight);
  color: var(--color-highlight-text);
}

.column__label {
  font-size: 0.7rem;
  color: var(--color-text-faint);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.column__tip {
  position: absolute;
  bottom: calc(100% + 0.35rem);
  left: 50%;
  transform: translateX(-50%);
  z-index: 3;
  min-width: 8rem;
  padding: 0.5rem 0.65rem;
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-md);
  pointer-events: none;
}

.column__tip-title {
  margin: 0;
  font-size: 0.72rem;
  color: var(--color-text-muted);
}

.column__tip-value {
  margin: 0.15rem 0 0;
  font-size: 0.85rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.column__tip-detail {
  margin: 0.15rem 0 0;
  font-size: 0.72rem;
  color: var(--color-text-muted);
  white-space: nowrap;
}

@media (max-width: 48rem) {
  /* На телефоне 24 подписи часов не помещаются — остаются чётные. */
  .column:nth-child(even) .column__label {
    display: none;
  }

  .column__value:not(.column__value--peak) {
    display: none;
  }

  .column__fill {
    border-radius: var(--radius-sm) var(--radius-sm) 0 0;
  }
}
</style>