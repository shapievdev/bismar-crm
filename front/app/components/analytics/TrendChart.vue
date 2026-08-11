<script setup lang="ts">
import type { Granularity } from '~/types/analytics'
import { formatBucket, formatCompact, formatCompactMoney, formatMoney, formatNumber } from '~/utils/numbers'

export interface TrendSeries {
  key: string
  label: string
  /**
   * Чем серия нарисована. Первая — сплошной линией по штриховке, вторая —
   * тонкой линией без заливки. Различает их не цвет, а фактура: график
   * остаётся читаемым в чёрно-белой печати и при любой цветовой слепоте.
   */
  slot: 1 | 2
  format?: 'money' | 'number'
}

const props = withDefaults(defineProps<{
  points: Record<string, unknown>[]
  series: TrendSeries[]
  granularity?: Granularity
  /** Заливку под первой серией можно снять — например, у почти плоских линий. */
  area?: boolean
  height?: number
}>(), {
  granularity: 'day',
  area: true,
  height: 240,
})

/*
 * Обе серии рисуются на одной шкале. Две оси Y — самая частая ошибка в
 * графиках: подобрав масштабы, на них можно показать любое соотношение, и
 * пересечение линий начинает означать событие, которого не было. Поэтому
 * величины разной природы (рубли и штуки) сюда вместе не кладутся — для них
 * два графика рядом.
 *
 * Шкалы слева нет вовсе, и это осознанно. Порядок величины держат плитки над
 * графиком, пик подписан прямо на нём, остальные точки читаются наведением.
 * Пять подписей «120 млн ₽» вдоль края добавляли бы к этому только шум.
 */

const PADDING = { top: 34, right: 20, bottom: 26, left: 20 }
const MIN_WIDTH = 280

/** Точки на вершинах перестают читаться, когда их больше, чем зазор между. */
const MAX_DOTTED_POINTS = 45

const root = ref<HTMLElement | null>(null)
const width = ref(720)

/** Своя штриховка на каждый график: два id на странице перекрыли бы друг друга. */
const patternId = `hatch-${useId()}`

let observer: ResizeObserver | null = null

onMounted(() => {
  if (!root.value) {
    return
  }

  // Ширина берётся сразу, а не с первым срабатыванием наблюдателя: тот
  // приходит следующим кадром, и график успевал мелькнуть узким.
  width.value = Math.max(MIN_WIDTH, root.value.clientWidth)

  observer = new ResizeObserver((entries) => {
    const measured = entries[0]?.contentRect.width ?? MIN_WIDTH
    width.value = Math.max(MIN_WIDTH, measured)
  })

  observer.observe(root.value)
})

onBeforeUnmount(() => observer?.disconnect())

const plot = computed(() => ({
  width: Math.max(1, width.value - PADDING.left - PADDING.right),
  height: Math.max(1, props.height - PADDING.top - PADDING.bottom),
}))

function valueAt(point: Record<string, unknown>, key: string): number {
  return Number(point[key] ?? 0)
}

/**
 * Верх шкалы. Всегда от нуля: усечённая ось преувеличивает колебание — рост на
 * два процента выглядит скачком вдвое.
 */
const maxValue = computed(() => {
  const values = props.points.flatMap(point => props.series.map(s => valueAt(point, s.key)))
  const max = Math.max(0, ...values)

  return max === 0 ? 1 : max
})

function x(index: number): number {
  if (props.points.length <= 1) {
    return PADDING.left + plot.value.width / 2
  }

  return PADDING.left + (index / (props.points.length - 1)) * plot.value.width
}

function y(value: number): number {
  return PADDING.top + plot.value.height - (value / maxValue.value) * plot.value.height
}

const baseline = computed(() => PADDING.top + plot.value.height)

/**
 * Сглаженная линия — монотонная кубическая, а не обычная кривая Безье.
 *
 * Разница принципиальная: обычное сглаживание выносит кривую за пределы
 * соседних точек и рисует провал там, где выручка просто не росла. Монотонная
 * интерполяция этого не делает — между двумя точками кривая остаётся в
 * границах их значений, и на графике не появляется дней, которых не было.
 */
function smoothPath(key: string): string {
  const points = props.points.map((point, index) => ({
    x: x(index),
    y: y(valueAt(point, key)),
  }))

  if (points.length === 0) {
    return ''
  }

  if (points.length < 3) {
    return points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x.toFixed(1)} ${point.y.toFixed(1)}`).join(' ')
  }

  // Наклон в каждой точке: средний между соседними отрезками, но обнулённый
  // на всяком развороте — так вершина остаётся вершиной, а не заворачивается.
  const slopes = points.map((point, index) => {
    const previous = points[index - 1]
    const next = points[index + 1]

    if (!previous || !next) {
      const neighbour = previous ?? next!

      return (point.y - neighbour.y) / (point.x - neighbour.x || 1)
    }

    const left = (point.y - previous.y) / (point.x - previous.x || 1)
    const right = (next.y - point.y) / (next.x - point.x || 1)

    return left * right <= 0 ? 0 : (left + right) / 2
  })

  let path = `M ${points[0]!.x.toFixed(1)} ${points[0]!.y.toFixed(1)}`

  for (let index = 1; index < points.length; index += 1) {
    const from = points[index - 1]!
    const to = points[index]!
    const span = (to.x - from.x) / 3

    const c1x = from.x + span
    const c1y = from.y + slopes[index - 1]! * span
    const c2x = to.x - span
    const c2y = to.y - slopes[index]! * span

    path += ` C ${c1x.toFixed(1)} ${c1y.toFixed(1)}, ${c2x.toFixed(1)} ${c2y.toFixed(1)}, ${to.x.toFixed(1)} ${to.y.toFixed(1)}`
  }

  return path
}

/**
 * Заштрихованная площадь.
 *
 * При двух сериях она лежит **между** ними, а не под верхней, и это не приём
 * оформления: разрыв между линиями — сам по себе величина, за которой ходят на
 * график. Между выручкой и прибылью в нём себестоимость, между дебиторкой и
 * кредиторкой — перекос расчётов, между посетителями и чеками — те, кто ушёл
 * без покупки. Заливка до нуля этот зазор ещё и закрывала бы: нижняя линия
 * тонет в штриховке верхней.
 *
 * При одной серии штриховать нечего, кроме площади под ней.
 */
function areaPath(): string {
  const upper = props.series[0]

  if (!upper || props.points.length === 0) {
    return ''
  }

  const top = smoothPath(upper.key)
  const lower = props.series[1]

  if (!lower) {
    return `${top} L ${x(props.points.length - 1).toFixed(1)} ${baseline.value} L ${x(0).toFixed(1)} ${baseline.value} Z`
  }

  // Нижняя граница проходится в обратную сторону, чтобы контур замкнулся.
  const bottom = props.points
    .map((point, index) => ({ x: x(index), y: y(valueAt(point, lower.key)) }))
    .reverse()
    .map((point, index) => `${index === 0 ? 'L' : 'L'} ${point.x.toFixed(1)} ${point.y.toFixed(1)}`)
    .join(' ')

  return `${top} ${bottom} Z`
}

const showDots = computed(() => props.points.length <= MAX_DOTTED_POINTS)

function label(point: Record<string, unknown>): string {
  return formatBucket(String(point.bucket ?? ''), props.granularity)
}

function formatValue(value: number, format: TrendSeries['format']): string {
  return format === 'money' ? formatMoney(value) : formatNumber(value)
}

function formatBadge(value: number, format: TrendSeries['format']): string {
  return format === 'money' ? formatCompactMoney(value) : formatCompact(value)
}

/**
 * Пик первой серии — единственное, что подписано прямо на графике.
 *
 * Он же задаёт масштаб вместо шкалы: зная высшую точку и то, что низ — ноль,
 * остальные читаются на глаз. Подписывать каждую точку значило бы вернуть на
 * график ту самую сетку чисел, ради отсутствия которой всё и затевалось.
 */
const peak = computed(() => {
  const first = props.series[0]

  if (!first || props.points.length === 0) {
    return null
  }

  let index = 0

  props.points.forEach((point, position) => {
    if (valueAt(point, first.key) > valueAt(props.points[index]!, first.key)) {
      index = position
    }
  })

  const value = valueAt(props.points[index]!, first.key)

  return value > 0 ? { index, value, format: first.format } : null
})

/*
 * Подписи оси X прореживаются: на квартале в 90 точек все даты сливаются в
 * серую полосу. Показывается около шести — начало, конец и ровные доли между.
 */
const xLabels = computed(() => {
  const count = props.points.length

  if (count === 0) {
    return []
  }

  const step = Math.max(1, Math.ceil(count / 6))

  return props.points
    .map((point, index) => ({ point, index }))
    .filter(({ index }) => index % step === 0 || index === count - 1)
})

const hovered = ref<number | null>(null)

/** Ближайшая к курсору точка: попадать нужно в график, а не в пиксель линии. */
function handleMove(event: MouseEvent) {
  if (props.points.length === 0 || !root.value) {
    return
  }

  const bounds = root.value.getBoundingClientRect()
  const offset = event.clientX - bounds.left - PADDING.left
  const ratio = offset / plot.value.width
  const index = Math.round(ratio * (props.points.length - 1))

  hovered.value = Math.min(props.points.length - 1, Math.max(0, index))
}

const hoveredPoint = computed(() =>
  hovered.value === null ? null : props.points[hovered.value] ?? null,
)

/** Подсказка не должна уезжать за край: у краёв она разворачивается внутрь. */
const tooltipStyle = computed(() => {
  if (hovered.value === null) {
    return {}
  }

  const position = x(hovered.value)
  const isRightHalf = position > width.value / 2

  return {
    left: `${position}px`,
    transform: isRightHalf ? 'translate(-100%, 0)' : 'translate(0, 0)',
    marginLeft: isRightHalf ? '-0.6rem' : '0.6rem',
  }
})

/** Бейдж пика прижимается к краю так же, как подсказка. */
const badgeAnchor = computed(() => {
  if (!peak.value) {
    return null
  }

  const position = x(peak.value.index)
  const edge = 46

  return {
    x: Math.min(Math.max(position, PADDING.left + edge), width.value - PADDING.right - edge),
    y: y(peak.value.value),
  }
})
</script>

<template>
  <div ref="root" class="trend">
    <!-- Легенда есть всегда при двух сериях: одна фактура должна быть названа
         так же явно, как другая. -->
    <ul v-if="series.length > 1" class="legend">
      <li v-for="item in series" :key="item.key" class="legend__item">
        <span class="legend__swatch" :class="`legend__swatch--${item.slot}`" aria-hidden="true" />
        {{ item.label }}
      </li>
    </ul>

    <div class="trend__plot">
      <svg
        :width="width"
        :height="height"
        :viewBox="`0 0 ${width} ${height}`"
        role="img"
        :aria-label="`График: ${series.map(s => s.label).join(' и ')}`"
        @mousemove="handleMove"
        @mouseleave="hovered = null"
      >
        <defs>
          <!-- Диагональная штриховка вместо сплошной заливки: она отделяет
               площадь от фона, ничего не закрашивая, и переживает и печать, и
               режим высокой контрастности. -->
          <pattern :id="patternId" width="7" height="7" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
            <line x1="0" y1="0" x2="0" y2="7" class="hatch" />
          </pattern>
        </defs>

        <!-- Базовая линия — единственное, что осталось от координатной сетки:
             без неё нулю не на чем стоять. -->
        <line
          class="baseline"
          :x1="PADDING.left"
          :x2="width - PADDING.right"
          :y1="baseline"
          :y2="baseline"
        />

        <path
          v-if="area && series[0]"
          class="area"
          :d="areaPath()"
          :fill="`url(#${patternId})`"
        />

        <path
          v-for="item in series"
          :key="item.key"
          class="line"
          :class="`line--${item.slot}`"
          :d="smoothPath(item.key)"
        />

        <template v-if="showDots">
          <g v-for="item in series" :key="`dots-${item.key}`">
            <circle
              v-for="(point, index) in points"
              :key="index"
              class="dot"
              :class="`dot--${item.slot}`"
              :cx="x(index)"
              :cy="y(valueAt(point, item.key))"
              r="2.5"
            />
          </g>
        </template>

        <!-- Выноска к пику: линия к точке и пилюля с числом над ней. -->
        <g v-if="peak && badgeAnchor" class="peak">
          <line
            class="peak__leader"
            :x1="x(peak.index)"
            :x2="x(peak.index)"
            :y1="badgeAnchor.y - 6"
            :y2="PADDING.top - 8"
          />
          <circle class="peak__dot" :cx="x(peak.index)" :cy="badgeAnchor.y" r="3.5" />
        </g>

        <g v-if="hovered !== null">
          <line
            class="crosshair"
            :x1="x(hovered)"
            :x2="x(hovered)"
            :y1="PADDING.top"
            :y2="baseline"
          />

          <circle
            v-for="item in series"
            :key="item.key"
            class="marker"
            :class="`marker--${item.slot}`"
            :cx="x(hovered)"
            :cy="y(valueAt(points[hovered]!, item.key))"
            r="4.5"
          />
        </g>

        <g class="axis">
          <text
            v-for="{ point, index } in xLabels"
            :key="index"
            :x="x(index)"
            :y="height - 6"
            text-anchor="middle"
          >
            {{ label(point) }}
          </text>
        </g>
      </svg>

      <!-- Пилюля рисуется разметкой, а не в SVG: так у неё та же типографика,
           что у остального интерфейса, и её не надо мерить руками. -->
      <span
        v-if="peak && badgeAnchor"
        class="badge"
        :style="{ left: `${badgeAnchor.x}px` }"
      >{{ formatBadge(peak.value, peak.format) }}</span>

      <div v-if="hoveredPoint" class="tooltip" :style="tooltipStyle">
        <p class="tooltip__title">
          {{ label(hoveredPoint) }}
        </p>

        <p v-for="item in series" :key="item.key" class="tooltip__row">
          <span class="tooltip__swatch" :class="`legend__swatch--${item.slot}`" aria-hidden="true" />
          <span class="tooltip__label">{{ item.label }}</span>
          <span class="tooltip__value">{{ formatValue(valueAt(hoveredPoint, item.key), item.format) }}</span>
        </p>
      </div>
    </div>

    <!-- Те же данные таблицей: для чтения с экрана и для случая, когда график
         недоступен. Визуально скрыта, но остаётся в дереве доступности. -->
    <table class="visually-hidden">
      <caption>{{ series.map(s => s.label).join(', ') }}</caption>
      <thead>
        <tr>
          <th scope="col">Период</th>
          <th v-for="item in series" :key="item.key" scope="col">
            {{ item.label }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(point, index) in points" :key="index">
          <th scope="row">{{ label(point) }}</th>
          <td v-for="item in series" :key="item.key">
            {{ formatValue(valueAt(point, item.key), item.format) }}
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
/*
 * Графики монохромны, и лайм на них означает ровно одно — «вот это число».
 * Серии различает фактура: первая идёт по штриховке, вторая — тонкой светлой
 * линией. Цвет как признак здесь не задействован вовсе, поэтому ни печать, ни
 * дальтонизм ничего не отнимают.
 */
.trend {
  --chart-ink: var(--color-text);
  --chart-muted: var(--color-text-faint);
  --chart-hatch: var(--color-border-strong);
  position: relative;
  min-width: 0;
}

.trend__plot {
  position: relative;
}

svg {
  display: block;
  max-width: 100%;
  overflow: visible;
}

.hatch {
  stroke: var(--chart-hatch);
  stroke-width: 1;
  opacity: 0.55;
}

.baseline {
  stroke: var(--color-border);
  stroke-width: 1;
}

.area {
  stroke: none;
}

.line {
  fill: none;
  stroke-linecap: round;
  stroke-linejoin: round;
}

/* Ведущая серия темнее и толще ведомой — их не приходится сверять с легендой. */
.line--1 {
  stroke: var(--chart-ink);
  stroke-width: 1.75;
}

.line--2 {
  stroke: var(--chart-muted);
  stroke-width: 1.25;
}

.dot--1 { fill: var(--chart-ink); }
.dot--2 { fill: var(--chart-muted); }

.peak__leader {
  stroke: var(--color-border-strong);
  stroke-width: 1;
}

.peak__dot {
  fill: var(--chart-ink);
  stroke: var(--color-surface-raised);
  stroke-width: 2;
}

.badge {
  position: absolute;
  top: 0;
  transform: translateX(-50%);
  padding: 0.2rem 0.55rem;
  border-radius: var(--radius-pill);
  background: var(--color-highlight);
  color: var(--color-highlight-text);
  font-size: 0.72rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  pointer-events: none;
}

.crosshair {
  stroke: var(--color-border-strong);
  stroke-width: 1;
  stroke-dasharray: 3 3;
}

.marker {
  fill: var(--chart-ink);
  /* Кольцо тоном поверхности отделяет маркер от линии под ним. */
  stroke: var(--color-surface-raised);
  stroke-width: 2;
}

.marker--2 {
  fill: var(--chart-muted);
}

.axis text {
  fill: var(--color-text-faint);
  font-size: 0.7rem;
  font-variant-numeric: tabular-nums;
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 1rem;
  margin: 0 0 0.4rem;
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

/* Свотчи повторяют фактуру линий, а не красят квадратик в её цвет. */
.legend__swatch {
  width: 1.1rem;
  height: 0;
  border-top: 1.75px solid var(--chart-ink);
  flex-shrink: 0;
}

.legend__swatch--2 {
  border-top-width: 1.25px;
  border-top-color: var(--chart-muted);
}

.tooltip {
  position: absolute;
  top: 0;
  pointer-events: none;
  min-width: 11rem;
  padding: 0.6rem 0.75rem;
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-md);
  font-size: 0.78rem;
  z-index: 2;
}

.tooltip__title {
  margin: 0 0 0.35rem;
  font-weight: 600;
}

.tooltip__row {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0.2rem 0 0;
}

.tooltip__swatch {
  width: 0.8rem;
  height: 0;
  border-top: 1.75px solid var(--chart-ink);
  flex-shrink: 0;
}

.tooltip__label {
  color: var(--color-text-muted);
}

.tooltip__value {
  margin-left: auto;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  margin: -1px;
  padding: 0;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
  border: 0;
}
</style>