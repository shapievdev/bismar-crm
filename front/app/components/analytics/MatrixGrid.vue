<script setup lang="ts">
import type { MatrixCell } from '~/types/analytics'
import { formatCompactMoney, formatMoney, formatNumber } from '~/utils/numbers'

const props = defineProps<{
  cells: MatrixCell[]
}>()

/*
 * Матрица — это тепловая карта, а не набор категорий: клетки отличаются
 * величиной, а не смыслом. Поэтому тон один, и насыщенность растёт вместе с
 * выручкой — ровно то, для чего последовательная шкала и нужна. Раскрасить
 * девять клеток девятью цветами значило бы сказать, что они несопоставимы.
 */
const maximum = computed(() => Math.max(1, ...props.cells.map(cell => cell.revenue)))

const ABC = ['A', 'B', 'C'] as const
const XYZ = ['X', 'Y', 'Z'] as const

function cellAt(abc: string, xyz: string): MatrixCell | undefined {
  return props.cells.find(cell => cell.abc === abc && cell.xyz === xyz)
}

/** Доля от максимума — она же интенсивность заливки. */
function intensity(revenue: number): number {
  return Math.round((revenue / maximum.value) * 100)
}

/** Тёмная клетка требует светлой подписи — иначе текст в ней тонет. */
function isDark(revenue: number): boolean {
  return intensity(revenue) > 55
}

const total = computed(() => props.cells.reduce((sum, cell) => sum + cell.revenue, 0))
</script>

<template>
  <div class="matrix">
    <div class="matrix__grid">
      <!-- Заголовок столбцов: XYZ — ровность спроса. -->
      <span class="matrix__corner" />
      <span v-for="xyz in XYZ" :key="xyz" class="matrix__head">{{ xyz }}</span>

      <template v-for="abc in ABC" :key="abc">
        <span class="matrix__head matrix__head--row">{{ abc }}</span>

        <div
          v-for="xyz in XYZ"
          :key="`${abc}${xyz}`"
          class="cell"
          :class="{ 'cell--dark': isDark(cellAt(abc, xyz)?.revenue ?? 0) }"
          :style="{ '--fill': `${intensity(cellAt(abc, xyz)?.revenue ?? 0)}%` }"
          :title="`${abc}${xyz}: ${formatMoney(cellAt(abc, xyz)?.revenue ?? 0)}`"
        >
          <span class="cell__value">{{ formatCompactMoney(cellAt(abc, xyz)?.revenue ?? 0) }}</span>
          <span class="cell__meta">{{ formatNumber(cellAt(abc, xyz)?.items ?? 0) }} SKU</span>
        </div>
      </template>
    </div>

    <dl class="legend">
      <div>
        <dt>ABC</dt>
        <dd>доля в обороте: A кормит, C замыкает хвост</dd>
      </div>
      <div>
        <dt>XYZ</dt>
        <dd>ровность спроса: X берут стабильно, Z — случайно</dd>
      </div>
      <div>
        <dt>Итого</dt>
        <dd>{{ formatCompactMoney(total) }} в размеченном ассортименте</dd>
      </div>
    </dl>
  </div>
</template>

<style scoped>
.matrix {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-width: 0;
}

.matrix__grid {
  display: grid;
  grid-template-columns: 1.5rem repeat(3, minmax(0, 1fr));
  gap: 4px;
  align-items: stretch;
}

.matrix__corner {
  content: '';
}

.matrix__head {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-faint);
}

.matrix__head--row {
  justify-content: flex-start;
}

.cell {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 0.15rem;
  min-height: 4.5rem;
  padding: 0.6rem;
  border-radius: var(--radius-sm);
  /* Одна шкала: от тона поверхности к тону текста, доля — сама величина. */
  background: color-mix(in srgb, var(--color-text) var(--fill), var(--color-surface-sunken));
}

.cell__value {
  font-size: 0.85rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  color: var(--color-text);
}

.cell__meta {
  font-size: 0.7rem;
  color: var(--color-text-muted);
}

/* На залитой клетке подпись берёт цвет страницы, а не текста. */
.cell--dark .cell__value {
  color: var(--color-bg);
}

.cell--dark .cell__meta {
  color: color-mix(in srgb, var(--color-bg) 75%, transparent);
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 1.25rem;
  margin: 0;
  font-size: 0.74rem;
}

.legend div {
  display: flex;
  gap: 0.35rem;
}

.legend dt {
  font-weight: 600;
  color: var(--color-text-muted);
}

.legend dd {
  margin: 0;
  color: var(--color-text-faint);
}
</style>