<script setup lang="ts">
import { formatCompactMoney, formatMoney, formatPercent } from '~/utils/numbers'

export interface DonutSlice {
  name: string
  value: number
}

const props = withDefaults(defineProps<{
  slices: DonutSlice[]
  /** Подпись в середине кольца — то, ради чего у него есть дырка. */
  centerLabel?: string
  size?: number
}>(), {
  size: 168,
})

/*
 * Кольцо, а не пирог: в середине помещается итог, и доля читается вдвое легче,
 * когда рядом стоит число, из которого она берётся.
 *
 * Долей здесь три-четыре — дальше сегменты становятся тоньше подписи. Хвост
 * сворачивается в «остальное»: пять полосок по два процента не сообщают
 * ничего, кроме того, что их пять.
 */
const MAX_SLICES = 4

const prepared = computed(() => {
  const sorted = [...props.slices].filter(slice => slice.value > 0).sort((a, b) => b.value - a.value)

  if (sorted.length <= MAX_SLICES) {
    return sorted
  }

  const head = sorted.slice(0, MAX_SLICES - 1)
  const tail = sorted.slice(MAX_SLICES - 1)

  return [...head, {
    name: 'Остальное',
    value: tail.reduce((sum, slice) => sum + slice.value, 0),
  }]
})

const total = computed(() => prepared.value.reduce((sum, slice) => sum + slice.value, 0))

const RADIUS = 54
const CIRCUMFERENCE = 2 * Math.PI * RADIUS

/** Сегменты как отрезки одной окружности — со сдвигом на сумму предыдущих. */
const segments = computed(() => {
  let consumed = 0

  return prepared.value.map((slice, index) => {
    const share = total.value > 0 ? slice.value / total.value : 0
    // Зазор в два пикселя тоном поверхности: соседние сегменты не должны
    // сливаться в сплошное кольцо.
    const length = Math.max(0, share * CIRCUMFERENCE - 2)
    const offset = consumed

    consumed += share * CIRCUMFERENCE

    return {
      ...slice,
      share: share * 100,
      slot: index + 1,
      dash: `${length} ${CIRCUMFERENCE - length}`,
      offset: -offset,
    }
  })
})

const hovered = ref<number | null>(null)
</script>

<template>
  <div class="donut">
    <div class="donut__chart" :style="{ width: `${size}px`, height: `${size}px` }">
      <svg viewBox="0 0 128 128" role="img" :aria-label="`Доли: ${prepared.map(s => s.name).join(', ')}`">
        <!-- Дорожка под сегментами: на неполном круге видно, что он неполон. -->
        <circle class="track" cx="64" cy="64" :r="RADIUS" />

        <circle
          v-for="segment in segments"
          :key="segment.name"
          class="segment"
          :class="[`segment--${segment.slot}`, { 'segment--dim': hovered !== null && hovered !== segment.slot }]"
          cx="64"
          cy="64"
          :r="RADIUS"
          :stroke-dasharray="segment.dash"
          :stroke-dashoffset="segment.offset"
          @mouseenter="hovered = segment.slot"
          @mouseleave="hovered = null"
        />
      </svg>

      <div class="donut__center">
        <p class="donut__total">
          {{ formatCompactMoney(total) }}
        </p>
        <p v-if="centerLabel" class="donut__caption">
          {{ centerLabel }}
        </p>
      </div>
    </div>

    <ul class="legend">
      <li
        v-for="segment in segments"
        :key="segment.name"
        class="legend__item"
        :class="{ 'legend__item--dim': hovered !== null && hovered !== segment.slot }"
        @mouseenter="hovered = segment.slot"
        @mouseleave="hovered = null"
      >
        <span class="legend__swatch" :class="`legend__swatch--${segment.slot}`" aria-hidden="true" />
        <span class="legend__name">{{ segment.name }}</span>
        <span class="legend__value" :title="formatMoney(segment.value)">
          {{ formatPercent(segment.share) }}
        </span>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.donut {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1.25rem;
  min-width: 0;
}

.donut__chart {
  position: relative;
  flex-shrink: 0;
}

svg {
  width: 100%;
  height: 100%;
  /* Отсчёт с двенадцати часов, как читают циферблат. */
  transform: rotate(-90deg);
}

.track {
  fill: none;
  stroke: var(--color-surface-sunken);
  stroke-width: 16;
}

/*
 * Доли различает плотность, а не цвет: первая сплошная, дальше штриховка и
 * точки. Иначе кольцо потребовало бы четырёх цветов, ни один из которых не
 * значил бы ничего, кроме «это другая доля».
 */
.segment {
  fill: none;
  stroke-width: 16;
  stroke-linecap: butt;
  transition: opacity 0.12s ease;
}

.segment--1 { stroke: var(--color-text); }
.segment--2 { stroke: var(--color-text-muted); }
.segment--3 { stroke: var(--color-border-strong); }
.segment--4 { stroke: var(--color-text-faint); }

.segment--dim {
  opacity: 0.35;
}

.donut__center {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.1rem;
  pointer-events: none;
  text-align: center;
}

.donut__total {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.donut__caption {
  margin: 0;
  font-size: 0.7rem;
  color: var(--color-text-faint);
}

.legend {
  flex: 1;
  min-width: 9rem;
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  font-size: 0.8rem;
}

.legend__item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  transition: opacity 0.12s ease;
}

.legend__item--dim {
  opacity: 0.45;
}

.legend__swatch {
  width: 0.7rem;
  height: 0.7rem;
  border-radius: 3px;
  flex-shrink: 0;
}

.legend__swatch--1 { background: var(--color-text); }
.legend__swatch--2 { background: var(--color-text-muted); }
.legend__swatch--3 { background: var(--color-border-strong); }
.legend__swatch--4 { background: var(--color-text-faint); }

.legend__name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--color-text-muted);
}

.legend__value {
  margin-left: auto;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
</style>