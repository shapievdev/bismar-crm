<script setup lang="ts">
import type { BarRow } from '~/components/analytics/BarList.vue'
import type { DonutSlice } from '~/components/analytics/DonutChart.vue'
import type { ScatterPoint } from '~/components/analytics/ScatterPlot.vue'
import type { ProductDimension } from '~/types/analytics'
import { formatNumber, formatPercent } from '~/utils/numbers'

definePageMeta({ middleware: 'auth', permission: 'analytics.view' })

useHead({ title: 'Товары — Аналитика' })

const filters = useAnalyticsFilters()
const { data: directory } = useAnalyticsDirectory()
const { fetchProducts, fetchProductBreakdown } = useAnalyticsApi()

const { data: products, pending, error } = await useAsyncData(
  'analytics-products',
  async () => (await fetchProducts(filters.value)).data,
  { watch: [filters] },
)

const dimension = ref<ProductDimension>('brand')

const { data: breakdown, pending: breakdownPending } = await useAsyncData(
  'analytics-products-breakdown',
  async () => (await fetchProductBreakdown(filters.value, dimension.value)).data,
  { watch: [filters, dimension] },
)

const message = computed(() =>
  (error.value?.data as { message?: string } | undefined)?.message
  ?? 'Сервер аналитики недоступен. Цифры появятся, когда связь восстановится.',
)

/** Подпись текущего разреза — она же ось графика: «оборот бренда», не «оборот». */
const dimensionLabel = computed(() =>
  (directory.value?.product_dimensions ?? []).find(option => option.value === dimension.value)?.label
  ?? 'Разрез',
)

const rankRows = computed<BarRow[]>(() =>
  (breakdown.value ?? []).map(row => ({
    name: row.name,
    value: row.revenue,
    meta: `маржа ${formatPercent(row.margin)} · ${formatNumber(row.items)} наименований`,
  })),
)

/*
 * Оборот против маржи, размер точки — широта ассортимента за строкой. Рейтинг
 * рядом отвечает, кто продаётся больше всех, и молчит о том, зарабатывают ли на
 * этом: бренд с первого места и маржой вдвое ниже средней виден только здесь.
 */
const rankPoints = computed<ScatterPoint[]>(() =>
  (breakdown.value ?? [])
    .filter(row => row.revenue > 0)
    .map(row => ({
      name: row.name,
      x: row.revenue,
      y: row.margin,
      weight: row.items,
    })),
)

/** Средняя маржа разреза — линия, относительно которой читается вертикаль. */
const averageMargin = computed(() => {
  const rows = breakdown.value ?? []
  const revenue = rows.reduce((sum, row) => sum + row.revenue, 0)
  const profit = rows.reduce((sum, row) => sum + row.profit, 0)

  return revenue > 0 ? Number((profit / revenue * 100).toFixed(1)) : undefined
})

/*
 * Доли ABC — кольцом: три класса, которые в сумме дают весь размеченный
 * ассортимент. Матрица рядом раскладывает те же деньги ещё и по ровности
 * спроса, но на вопрос «сколько даёт A» отвечает долго.
 */
const abcSlices = computed<DonutSlice[]>(() => {
  const byClass = new Map<string, number>()

  ;(products.value?.matrix ?? []).forEach((cell) => {
    byClass.set(cell.abc, (byClass.get(cell.abc) ?? 0) + cell.revenue)
  })

  return [...byClass.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([abc, revenue]) => ({ name: `Класс ${abc}`, value: revenue }))
})

/*
 * Неликвид — не про запас, а про то, уходит ли помеченное вообще. Метка стоит
 * на товаре, и строки здесь означают продажи такого товара, а не залежь.
 */
const illiquidRows = computed<BarRow[]>(() =>
  (products.value?.illiquid ?? []).map(row => ({
    name: row.status,
    value: row.revenue,
    meta: `маржа ${formatPercent(row.margin)} · ${formatNumber(row.items)} наименований`,
  })),
)

/** Категории неликвида продаются заметно хуже по марже — это стоит назвать. */
const illiquidMargin = computed(() => {
  const rows = products.value?.illiquid ?? []
  const liquid = rows.find(row => row.status.includes('ликвид'))
  const rest = rows.filter(row => row !== liquid)

  if (!liquid || rest.length === 0) {
    return null
  }

  const revenue = rest.reduce((sum, row) => sum + row.revenue, 0)
  const profit = rest.reduce((sum, row) => sum + row.profit, 0)

  return {
    liquid: liquid.margin,
    illiquid: revenue > 0 ? Number((profit / revenue * 100).toFixed(1)) : 0,
  }
})
</script>

<template>
  <div class="page">
    <header class="page__head">
      <h1 class="page__title">
        Товары
      </h1>
      <p class="page__lead">
        Что продаётся, что лежит и на чём зарабатывают. Разметка ABC/XYZ и неликвида сделана
        витриной на всей истории: посчитанная по одному месяцу, категория A означала бы всего
        лишь «продавалось в этом месяце».
      </p>
    </header>

    <AnalyticsFilterBar v-model="filters" :directory="directory" />
    <AnalyticsFreshnessNote :freshness="directory?.freshness ?? []" :sources="['sales']" />

    <p v-if="error" class="notice notice--error">
      {{ message }}
    </p>

    <AnalyticsBentoGrid v-else-if="products">
      <AnalyticsChartCard
        :title="`Оборот и маржа: ${dimensionLabel.toLowerCase()}`"
        hint="Горизонталь — оборот, вертикаль — маржа, размер точки — сколько наименований за ней стоит. Правый нижний угол: продаётся много, зарабатывается мало."
        :span="8"
        :rows="3"
      >
        <template #actions>
          <div class="switch">
            <button
              v-for="option in directory?.product_dimensions ?? []"
              :key="option.value"
              type="button"
              class="switch__item"
              :class="{ 'switch__item--active': dimension === option.value }"
              @click="dimension = option.value as ProductDimension"
            >
              {{ option.label }}
            </button>
          </div>
        </template>

        <AnalyticsScatterPlot :points="rankPoints" :benchmark="averageMargin" :height="300" />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Матрица ABC × XYZ"
        hint="Что держать на складе всегда, а что возить под заказ: AX — основа оборота с ровным спросом, CZ — случайные продажи хвоста."
        :span="4"
        :rows="3"
      >
        <AnalyticsMatrixGrid :cells="products.matrix" />
      </AnalyticsChartCard>

      <!-- Кольцо и неликвид одной высоты: у первого доли, у второго три-четыре
           строки, и в ряду они не тянут друг друга. -->
      <AnalyticsChartCard
        title="Доли ABC"
        hint="Класс — про долю в обороте: A даёт основную выручку, C замыкает хвост."
        :span="4"
        :rows="2"
      >
        <AnalyticsDonutChart
          :slices="abcSlices"
          center-label="размечено"
          :size="150"
        />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Неликвид"
        :hint="illiquidMargin
          ? `Помеченное неликвидом всё-таки продаётся, но с маржой ${formatPercent(illiquidMargin.illiquid)} против ${formatPercent(illiquidMargin.liquid)} у остального ассортимента.`
          : 'Продажи товаров, помеченных как неликвид. Метка стоит на товаре, а не на конкретной продаже.'"
        :span="8"
        :rows="2"
      >
        <AnalyticsBarList :rows="illiquidRows" />
      </AnalyticsChartCard>

      <!-- Рейтинг стоит в ряду один и потому растёт свободно: тянуть ему
           некого. -->
      <AnalyticsChartCard
        :title="`Рейтинг: ${dimensionLabel.toLowerCase()}`"
        hint="Тот же разрез по одной величине — по выручке за период."
        :span="12"
        :rows="2"
      >
        <AnalyticsBarList :rows="rankRows" />
        <p v-if="breakdownPending" class="loading">
          Считаем…
        </p>
      </AnalyticsChartCard>
    </AnalyticsBentoGrid>

    <p v-else-if="pending" class="loading">
      Считаем…
    </p>
  </div>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  --bento-row: 8.5rem;
}

.page__head {
  margin-bottom: 1.25rem;
}

.page__title {
  margin: 0;
  font-size: 1.6rem;
  font-weight: 600;
  letter-spacing: -0.02em;
}

.page__lead {
  margin: 0.35rem 0 0;
  font-size: 0.85rem;
  color: var(--color-text-muted);
  max-width: 62ch;
}

.switch {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
}

.switch__item {
  padding: 0.3rem 0.6rem;
  border: 0;
  border-radius: var(--radius-pill);
  background: var(--control-surface);
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.74rem;
  cursor: pointer;
  white-space: nowrap;
}

.switch__item:hover {
  background: var(--control-surface-hover);
  color: var(--color-text);
}

.switch__item--active,
.switch__item--active:hover {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.loading {
  margin: 0.75rem 0 0;
  font-size: 0.82rem;
  color: var(--color-text-faint);
}

.notice {
  margin: 0;
  padding: 0.85rem 1rem;
  border-radius: var(--radius-sm);
  font-size: 0.85rem;
}

.notice--error {
  background: var(--color-danger-soft);
  color: var(--color-danger);
}
</style>