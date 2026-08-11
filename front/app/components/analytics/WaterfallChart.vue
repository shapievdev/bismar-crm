<script setup lang="ts">
import { formatCompactMoney, formatMoney, formatPercent } from '~/utils/numbers'

const props = withDefaults(defineProps<{
  revenue: number
  cost: number
  profit: number
  margin: number
  height?: number
}>(), {
  height: 190,
})

/*
 * Водопад показывает не три числа, а одно превращение: выручка приходит,
 * себестоимость её съедает, остаётся прибыль. Три плитки рядом сообщили бы те
 * же величины, но не то, что вторая вычитается из первой, а третья — остаток.
 *
 * Средний столбец потому и висит в воздухе: он начинается там, где кончается
 * прибыль, и достаёт до вершины выручки.
 */
const base = computed(() => Math.max(1, props.revenue))

const bars = computed(() => [
  {
    key: 'revenue',
    label: 'Выручка',
    value: props.revenue,
    // Доля высоты и отступ снизу: столбец стоит на нуле.
    height: 100,
    offset: 0,
    kind: 'total' as const,
  },
  {
    key: 'cost',
    label: 'Себестоимость',
    value: -props.cost,
    height: (props.cost / base.value) * 100,
    offset: (props.profit / base.value) * 100,
    kind: 'negative' as const,
  },
  {
    key: 'profit',
    label: 'Валовая прибыль',
    value: props.profit,
    height: (props.profit / base.value) * 100,
    offset: 0,
    kind: 'result' as const,
  },
])

const hovered = ref<string | null>(null)
</script>

<template>
  <div class="waterfall" :style="{ '--plot-height': `${height}px` }">
    <div class="waterfall__row">
      <div
        v-for="bar in bars"
        :key="bar.key"
        class="bar"
        @mouseenter="hovered = bar.key"
        @mouseleave="hovered = null"
      >
        <span class="bar__value">{{ formatCompactMoney(bar.value) }}</span>

        <div class="bar__track">
          <div
            class="bar__fill"
            :class="`bar__fill--${bar.kind}`"
            :style="{ height: `${bar.height}%`, bottom: `${bar.offset}%` }"
          />
        </div>

        <span class="bar__label">{{ bar.label }}</span>

        <div v-if="hovered === bar.key" class="tip">
          {{ formatMoney(bar.value) }}
        </div>
      </div>
    </div>

    <p class="footnote">
      Из каждого рубля выручки остаётся {{ formatPercent(margin) }} валовой прибыли.
    </p>
  </div>
</template>

<style scoped>
.waterfall {
  min-width: 0;
}

.waterfall__row {
  display: flex;
  align-items: flex-end;
  gap: 1rem;
}

.bar {
  position: relative;
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
}

.bar__track {
  position: relative;
  width: 100%;
  height: var(--plot-height);
}

.bar__fill {
  position: absolute;
  left: 0;
  width: 100%;
  border-radius: var(--radius) var(--radius) 0 0;
  min-height: 3px;
}

/* Приход — сплошной, вычет — штриховкой, остаток — акцентом. */
.bar__fill--total {
  background: var(--color-surface-sunken);
}

.bar__fill--negative {
  background:
    repeating-linear-gradient(
      45deg,
      var(--color-border-strong) 0 1px,
      transparent 1px 7px
    ),
    var(--color-surface-sunken);
  /* Средний столбец висит между прибылью и выручкой — скругление с обеих сторон. */
  border-radius: var(--radius);
}

.bar__fill--result {
  background: var(--color-highlight);
}

.bar__value {
  font-size: 0.8rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.bar__label {
  font-size: 0.72rem;
  color: var(--color-text-faint);
  text-align: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
}

.tip {
  position: absolute;
  bottom: calc(100% + 0.3rem);
  left: 50%;
  transform: translateX(-50%);
  padding: 0.4rem 0.6rem;
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-md);
  font-size: 0.76rem;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  pointer-events: none;
  z-index: 2;
}

.footnote {
  margin: 0.75rem 0 0;
  font-size: 0.76rem;
  color: var(--color-text-muted);
}
</style>