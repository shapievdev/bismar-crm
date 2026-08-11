<script setup lang="ts">
import type { Granularity } from '~/types/analytics'
import { formatBucket, formatCompactMoney, formatMoney, formatPercent } from '~/utils/numbers'

export interface StackSlice {
  bucket: string
  name: string
  value: number
}

const props = withDefaults(defineProps<{
  slices: StackSlice[]
  granularity?: Granularity
  height?: number
}>(), {
  granularity: 'day',
  height: 200,
})

/*
 * Столбцы друг на друге отвечают на вопрос, на который линия ответить не может:
 * не «сколько всего», а «из чего это сложилось и менялось ли соотношение».
 * Высота столбца — итог периода, доли внутри — слагаемые.
 *
 * Слагаемых здесь два-три; на большем числе доли становятся неразличимыми
 * полосками, и честнее рисовать несколько графиков рядом.
 */

/** Имена в порядке убывания вклада: крупнейшее слагаемое всегда внизу. */
const names = computed(() => {
  const totals = new Map<string, number>()

  props.slices.forEach((slice) => {
    totals.set(slice.name, (totals.get(slice.name) ?? 0) + slice.value)
  })

  return [...totals.entries()]
    .sort(([, a], [, b]) => b - a)
    .map(([name]) => name)
})

const buckets = computed(() => {
  const grouped = new Map<string, Map<string, number>>()

  props.slices.forEach((slice) => {
    const bucket = grouped.get(slice.bucket) ?? new Map<string, number>()
    bucket.set(slice.name, (bucket.get(slice.name) ?? 0) + slice.value)
    grouped.set(slice.bucket, bucket)
  })

  return [...grouped.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([bucket, values]) => ({
      bucket,
      total: [...values.values()].reduce((sum, value) => sum + value, 0),
      values,
    }))
})

const maximum = computed(() => Math.max(1, ...buckets.value.map(bucket => bucket.total)))

const hovered = ref<number | null>(null)

/** Подписи прореживаются: тридцать дат под столбцами сливаются в полосу. */
const labelStep = computed(() => Math.max(1, Math.ceil(buckets.value.length / 8)))
</script>

<template>
  <div class="stack">
    <ul class="legend">
      <li v-for="(name, index) in names" :key="name" class="legend__item">
        <span class="legend__swatch" :class="`legend__swatch--${index + 1}`" aria-hidden="true" />
        {{ name }}
      </li>
    </ul>

    <div class="stack__row" :style="{ '--plot-height': `${height}px` }">
      <div
        v-for="(bucket, index) in buckets"
        :key="bucket.bucket"
        class="column"
        @mouseenter="hovered = index"
        @mouseleave="hovered = null"
      >
        <div class="column__track">
          <div
            v-for="(name, order) in names"
            :key="name"
            class="column__slice"
            :class="`column__slice--${order + 1}`"
            :style="{ height: `${((bucket.values.get(name) ?? 0) / maximum) * 100}%` }"
          />
        </div>

        <span class="column__label">
          {{ index % labelStep === 0 ? formatBucket(bucket.bucket, granularity) : '' }}
        </span>

        <div v-if="hovered === index" class="tip">
          <p class="tip__title">
            {{ formatBucket(bucket.bucket, granularity) }}
          </p>

          <p v-for="name in names" :key="name" class="tip__row">
            <span class="tip__label">{{ name }}</span>
            <span class="tip__value">
              {{ formatCompactMoney(bucket.values.get(name) ?? 0) }}
              <span class="tip__share">
                {{ formatPercent(bucket.total > 0 ? ((bucket.values.get(name) ?? 0) / bucket.total) * 100 : 0) }}
              </span>
            </span>
          </p>

          <p class="tip__total">
            Итого {{ formatMoney(bucket.total) }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.stack {
  min-width: 0;
}

.stack__row {
  display: flex;
  align-items: flex-end;
  gap: 2px;
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
  flex-direction: column-reverse;
  justify-content: flex-start;
  width: 100%;
  height: var(--plot-height);
}

/*
 * Слагаемые различает не цвет, а плотность: нижнее — сплошное, следующее —
 * штриховка, третье — точки. График остаётся читаемым и в печати, и при любой
 * цветовой слепоте, а лайм остаётся тем, чем был, — отметкой выбранного.
 */
.column__slice {
  width: 100%;
  min-height: 0;
}

.column__slice--1 {
  background: var(--color-text);
  border-radius: 0 0 var(--radius-sm) var(--radius-sm);
}

.column__slice--2 {
  background:
    repeating-linear-gradient(
      45deg,
      var(--color-text-faint) 0 1px,
      transparent 1px 6px
    ),
    var(--color-surface-sunken);
}

.column__slice--3 {
  background:
    radial-gradient(var(--color-text-faint) 0.7px, transparent 0.8px) 0 0 / 5px 5px,
    var(--color-surface-sunken);
}

/* Верхняя доля скругляется — столбец кончается формой, а не срезом. */
.column__track > .column__slice:last-child {
  border-radius: var(--radius) var(--radius) 0 0;
}

.column__label {
  height: 0.9rem;
  font-size: 0.68rem;
  color: var(--color-text-faint);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 1rem;
  margin: 0 0 0.6rem;
  padding: 0;
  list-style: none;
  font-size: 0.78rem;
  color: var(--color-text-muted);
}

.legend__item {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.legend__swatch {
  width: 0.7rem;
  height: 0.7rem;
  border-radius: 3px;
  flex-shrink: 0;
}

.legend__swatch--1 { background: var(--color-text); }

.legend__swatch--2 {
  background:
    repeating-linear-gradient(45deg, var(--color-text-faint) 0 1px, transparent 1px 4px),
    var(--color-surface-sunken);
}

.legend__swatch--3 {
  background:
    radial-gradient(var(--color-text-faint) 0.7px, transparent 0.8px) 0 0 / 4px 4px,
    var(--color-surface-sunken);
}

.tip {
  position: absolute;
  bottom: calc(100% + 0.35rem);
  left: 50%;
  transform: translateX(-50%);
  z-index: 3;
  min-width: 12rem;
  padding: 0.55rem 0.7rem;
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-md);
  pointer-events: none;
  font-size: 0.76rem;
}

.tip__title {
  margin: 0 0 0.3rem;
  font-weight: 600;
}

.tip__row {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  margin: 0.15rem 0 0;
  white-space: nowrap;
}

.tip__label {
  color: var(--color-text-muted);
}

.tip__value {
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.tip__share {
  margin-left: 0.3rem;
  font-weight: 400;
  color: var(--color-text-faint);
}

.tip__total {
  margin: 0.35rem 0 0;
  padding-top: 0.3rem;
  border-top: 1px solid var(--color-border);
  color: var(--color-text-muted);
  white-space: nowrap;
}

@media (max-width: 48rem) {
  .column__label {
    display: none;
  }
}
</style>