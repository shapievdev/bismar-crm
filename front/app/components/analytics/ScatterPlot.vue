<script setup lang="ts">
import { formatCompactMoney, formatMoney, formatNumber, formatPercent } from '~/utils/numbers'

export interface ScatterPoint {
  name: string
  /** Горизонталь — оборот. */
  x: number
  /** Вертикаль — маржа в процентах. */
  y: number
  /** Размер точки: сколько наименований за ней стоит. */
  weight: number
}

const props = withDefaults(defineProps<{
  points: ScatterPoint[]
  height?: number
  /** Линия, относительно которой читается вертикаль: средняя маржа. */
  benchmark?: number
}>(), {
  height: 260,
})

/*
 * Две величины сразу: оборот по горизонтали, маржа по вертикали, размер точки —
 * широта ассортимента. Ни рейтинг, ни линия так не умеют: они показывают одну
 * величину, а вопрос «что много продаётся, но плохо зарабатывает» задаётся про
 * две одновременно.
 *
 * Правый нижний угол — то, ради чего график и смотрят: большой оборот при
 * низкой марже.
 */

const PADDING = { top: 18, right: 20, bottom: 30, left: 46 }
const MIN_WIDTH = 280

const root = ref<HTMLElement | null>(null)
const width = ref(520)

let observer: ResizeObserver | null = null

onMounted(() => {
  if (!root.value) {
    return
  }

  width.value = Math.max(MIN_WIDTH, root.value.clientWidth)

  observer = new ResizeObserver((entries) => {
    width.value = Math.max(MIN_WIDTH, entries[0]?.contentRect.width ?? MIN_WIDTH)
  })

  observer.observe(root.value)
})

onBeforeUnmount(() => observer?.disconnect())

const plot = computed(() => ({
  width: Math.max(1, width.value - PADDING.left - PADDING.right),
  height: Math.max(1, props.height - PADDING.top - PADDING.bottom),
}))

const maxX = computed(() => Math.max(1, ...props.points.map(point => point.x)))

/** Вертикаль всегда включает ноль и запас сверху: точки не липнут к краю. */
const bounds = computed(() => {
  const values = props.points.map(point => point.y)
  const min = Math.min(0, ...values)
  const max = Math.max(1, ...values)
  const padding = (max - min) * 0.1

  return { min: min - padding, max: max + padding }
})

function x(value: number): number {
  return PADDING.left + (value / maxX.value) * plot.value.width
}

function y(value: number): number {
  const { min, max } = bounds.value

  return PADDING.top + plot.value.height - ((value - min) / (max - min)) * plot.value.height
}

/** Радиус по числу наименований — по корню, чтобы сравнивалась площадь. */
function radius(weight: number): number {
  const maxWeight = Math.max(1, ...props.points.map(point => point.weight))

  return 4 + Math.sqrt(weight / maxWeight) * 12
}

const hovered = ref<number | null>(null)

const hoveredPoint = computed(() => (hovered.value === null ? null : props.points[hovered.value] ?? null))

const tooltipStyle = computed(() => {
  if (hovered.value === null || !hoveredPoint.value) {
    return {}
  }

  const position = x(hoveredPoint.value.x)
  const isRightHalf = position > width.value / 2

  return {
    left: `${position}px`,
    top: `${y(hoveredPoint.value.y)}px`,
    transform: isRightHalf ? 'translate(-100%, -110%)' : 'translate(0, -110%)',
    marginLeft: isRightHalf ? '-0.6rem' : '0.6rem',
  }
})
</script>

<template>
  <div ref="root" class="scatter">
    <div class="scatter__plot">
      <svg
        :width="width"
        :height="height"
        :viewBox="`0 0 ${width} ${height}`"
        role="img"
        aria-label="Оборот и маржа: каждая точка — позиция рейтинга"
      >
        <!-- Нулевая маржа: всё, что под ней, продаётся в убыток. -->
        <line
          v-if="bounds.min < 0"
          class="zero"
          :x1="PADDING.left"
          :x2="width - PADDING.right"
          :y1="y(0)"
          :y2="y(0)"
        />

        <!-- Средняя маржа: относительно неё и читается высота точки. -->
        <template v-if="benchmark !== undefined">
          <line
            class="benchmark"
            :x1="PADDING.left"
            :x2="width - PADDING.right"
            :y1="y(benchmark)"
            :y2="y(benchmark)"
          />
          <text class="benchmark__label" :x="PADDING.left" :y="y(benchmark) - 6">
            средняя {{ formatPercent(benchmark) }}
          </text>
        </template>

        <circle
          v-for="(point, index) in points"
          :key="point.name"
          class="dot"
          :class="{ 'dot--loss': point.y < 0, 'dot--dim': hovered !== null && hovered !== index }"
          :cx="x(point.x)"
          :cy="y(point.y)"
          :r="radius(point.weight)"
          @mouseenter="hovered = index"
          @mouseleave="hovered = null"
        />

        <g class="axis">
          <text :x="PADDING.left" :y="height - 8" text-anchor="start">0 ₽</text>
          <text :x="width - PADDING.right" :y="height - 8" text-anchor="end">
            {{ formatCompactMoney(maxX) }}
          </text>
          <text :x="PADDING.left - 8" :y="PADDING.top + 4" text-anchor="end">
            {{ formatPercent(bounds.max) }}
          </text>
          <text :x="PADDING.left - 8" :y="PADDING.top + plot.height" text-anchor="end">
            {{ formatPercent(bounds.min) }}
          </text>
        </g>
      </svg>

      <div v-if="hoveredPoint" class="tooltip" :style="tooltipStyle">
        <p class="tooltip__title">
          {{ hoveredPoint.name }}
        </p>
        <p class="tooltip__row">
          <span>Оборот</span><span>{{ formatMoney(hoveredPoint.x) }}</span>
        </p>
        <p class="tooltip__row">
          <span>Маржа</span><span>{{ formatPercent(hoveredPoint.y) }}</span>
        </p>
        <p class="tooltip__row">
          <span>Наименований</span><span>{{ formatNumber(hoveredPoint.weight) }}</span>
        </p>
      </div>
    </div>

    <p class="hint">
      По горизонтали оборот, по вертикали маржа, размер точки — сколько наименований за ней стоит.
    </p>
  </div>
</template>

<style scoped>
.scatter {
  min-width: 0;
}

.scatter__plot {
  position: relative;
}

svg {
  display: block;
  max-width: 100%;
  overflow: visible;
}

.zero {
  stroke: var(--color-border-strong);
  stroke-width: 1;
}

.benchmark {
  stroke: var(--color-border-strong);
  stroke-width: 1;
  stroke-dasharray: 4 4;
}

.benchmark__label,
.axis text {
  fill: var(--color-text-faint);
  font-size: 0.68rem;
  font-variant-numeric: tabular-nums;
}

.dot {
  fill: var(--color-text);
  /* Кольцо тоном поверхности разделяет наложившиеся точки. */
  stroke: var(--color-surface-raised);
  stroke-width: 2;
  opacity: 0.85;
  transition: opacity 0.12s ease;
  cursor: default;
}

/* Убыток — не «мало», а другой полюс: он и красится иначе. */
.dot--loss {
  fill: var(--color-danger);
}

.dot--dim {
  opacity: 0.25;
}

.tooltip {
  position: absolute;
  pointer-events: none;
  min-width: 11rem;
  padding: 0.55rem 0.7rem;
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-md);
  font-size: 0.76rem;
  z-index: 2;
}

.tooltip__title {
  margin: 0 0 0.3rem;
  font-weight: 600;
  max-width: 16rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tooltip__row {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  margin: 0.15rem 0 0;
  white-space: nowrap;
}

.tooltip__row span:first-child {
  color: var(--color-text-muted);
}

.tooltip__row span:last-child {
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.hint {
  margin: 0.6rem 0 0;
  font-size: 0.74rem;
  color: var(--color-text-faint);
}
</style>