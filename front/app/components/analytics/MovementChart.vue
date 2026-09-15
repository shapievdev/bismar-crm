<script setup lang="ts">
export interface MovementMonth {
  month: string
  hired: number
  left: number
}

/**
 * Принято и уволено по месяцам — двумя потоками от общей оси.
 *
 * Не стопкой и не рядом: приём и уход — противоположные движения, а не
 * слагаемые одной величины. Сложи их в столбик — и получится «движение
 * персонала», число, которого не бывает; поставь рядом — и придётся сравнивать
 * длины двух соседних полосок вместо того, чтобы увидеть перевес. Ось посередине
 * показывает перевес сразу: месяц, где нижняя половина длиннее верхней, — это
 * месяц, когда компания уменьшилась.
 */
const props = withDefaults(defineProps<{
  months: MovementMonth[]
  height?: number
}>(), { height: 180 })

/** Одна шкала на обе половины: иначе «ушло 2» выглядело бы как «принято 8». */
const peak = computed(() => Math.max(1, ...props.months.flatMap(one => [one.hired, one.left])))

function share(value: number): number {
  return (value / peak.value) * 100
}

/** «2026-08» — «авг». Полное название месяца не помещается в столбец. */
function label(month: string): string {
  const [year, index] = month.split('-')

  return new Date(Number(year), Number(index) - 1, 1)
    .toLocaleDateString('ru-RU', { month: 'short' })
    .replace('.', '')
}

/** Год подписывается только там, где он сменился: двенадцать «2026» — шум. */
function year(month: string, at: number): string | null {
  const own = month.slice(0, 4)

  return at === 0 || props.months[at - 1]?.month.slice(0, 4) !== own ? own : null
}

const hovered = ref<number | null>(null)
</script>

<template>
  <div class="chart" :style="{ '--height': `${height}px` }">
    <!-- Обёртка нужна только на телефоне: там ряд месяцев не помещается и
         доматывается вбок. На широком экране она ничего не делает. -->
    <div class="chart__viewport">
      <div class="chart__plot">
        <div
          v-for="(month, at) in months"
          :key="month.month"
          class="month"
          :class="{ 'month--hovered': hovered === at }"
          @mouseenter="hovered = at"
          @mouseleave="hovered = null"
        >
          <span class="month__half month__half--up">
            <span
              class="bar bar--hired"
              :style="{ height: `${share(month.hired)}%` }"
              :title="`Принято ${month.hired}`"
            />
          </span>

          <span class="month__axis" />

          <span class="month__half month__half--down">
            <span
              class="bar bar--left"
              :style="{ height: `${share(month.left)}%` }"
              :title="`Уволено ${month.left}`"
            />
          </span>

          <span class="month__label">
            <span class="month__name">{{ label(month.month) }}</span>
            <span v-if="year(month.month, at)" class="month__year">{{ year(month.month, at) }}</span>
          </span>

          <!-- Числа показываются у того месяца, на который смотрят: подписать
               все сразу значит закрыть цифрами сам график. -->
          <span v-if="hovered === at" class="month__figures">
            +{{ month.hired }} / −{{ month.left }}
          </span>
        </div>
      </div>
    </div>

    <p class="chart__legend">
      <span class="chart__key chart__key--hired" />Принято
      <span class="chart__key chart__key--left" />Уволено
    </p>
  </div>
</template>

<style scoped>
.chart {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.chart__viewport {
  min-width: 0;
}

.chart__plot {
  display: flex;
  align-items: stretch;
  gap: 0.35rem;
  height: var(--height);
}

.month {
  position: relative;
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
}

.month__half {
  display: flex;
  flex: 1;
  justify-content: center;
}

/* Верхняя половина растёт вверх от оси, нижняя — вниз от неё. */
.month__half--up {
  align-items: flex-end;
}

.month__half--down {
  align-items: flex-start;
}

.month__axis {
  height: 1px;
  background: var(--color-border-strong);
}

.bar {
  width: 60%;
  min-height: 2px;
  border-radius: var(--radius-sm) var(--radius-sm) 0 0;
  transition: opacity 0.15s ease;
}

.bar--hired {
  background: var(--color-accent);
}

/* Уход — не «плохо», а другое направление: тот же вес, другой цвет. */
.bar--left {
  border-radius: 0 0 var(--radius-sm) var(--radius-sm);
  background: var(--color-border-strong);
}

.month--hovered .bar {
  opacity: 0.75;
}

.month__label {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding-top: 0.3rem;
  color: var(--color-text-muted);
  font-size: 0.7rem;
  line-height: 1.2;
}

.month__year {
  font-size: 0.62rem;
  opacity: 0.7;
}

.month__figures {
  position: absolute;
  top: -0.2rem;
  left: 50%;
  padding: 0.1rem 0.4rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-raised);
  box-shadow: var(--shadow-sm);
  color: var(--color-text);
  font-size: 0.7rem;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  transform: translate(-50%, -100%);
  pointer-events: none;
}

.chart__legend {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.75rem;
}

.chart__key {
  width: 0.7rem;
  height: 0.7rem;
  border-radius: 3px;
}

.chart__key--hired {
  background: var(--color-accent);
}

.chart__key--left {
  margin-left: 0.6rem;
  background: var(--color-border-strong);
}

/*
 * Телефон: месяцу отводится своя ширина, и ряд доматывается вбок.
 *
 * Год в триста сорок точек — это двадцать восемь точек на месяц: столбик
 * шириной в палец и подпись, налезающая на соседнюю. Сжимать дальше некуда, а
 * выкидывать месяцы нельзя — провал в середине года и есть то, ради чего на
 * график смотрят. Поэтому ряд не ужимается, а прокручивается: видно сразу
 * месяцев шесть, остальные — движением пальца.
 */
@media (max-width: 40rem) {
  .chart__viewport {
    overflow-x: auto;
    /* Прокрутка идёт по месяцам, а не останавливается посреди столбика. */
    scroll-snap-type: x proximity;
    /* Полоса прокрутки под графиком съедала бы его нижнюю строку подписей. */
    padding-bottom: 0.35rem;
  }

  .month {
    flex: 0 0 2.6rem;
    scroll-snap-align: start;
  }

  .month__label {
    font-size: 0.66rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .bar {
    transition: none;
  }
}
</style>
