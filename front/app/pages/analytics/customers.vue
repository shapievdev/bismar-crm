<script setup lang="ts">
import type { BarRow } from '~/components/analytics/BarList.vue'
import type { Column } from '~/components/analytics/ColumnChart.vue'
import type { DonutSlice } from '~/components/analytics/DonutChart.vue'
import type { ScatterPoint } from '~/components/analytics/ScatterPlot.vue'
import { formatCompactMoney, formatMoney, formatNumber, formatPercent } from '~/utils/numbers'

definePageMeta({ middleware: 'auth', permission: 'analytics.view' })

useHead({ title: 'Клиенты — Аналитика' })

const filters = useAnalyticsFilters()
const { data: directory } = useAnalyticsDirectory()
const { fetchCustomers } = useAnalyticsApi()

const { data: response, pending, error } = await useAsyncData(
  'analytics-customers',
  () => fetchCustomers(filters.value),
  { watch: [filters] },
)

const message = computed(() =>
  (error.value?.data as { message?: string } | undefined)?.message
  ?? 'Сервер аналитики недоступен. Цифры появятся, когда связь восстановится.',
)

const customers = computed(() => response.value?.data)
const current = computed(() => customers.value?.summary.current)
const previous = computed(() => customers.value?.summary.previous)

/*
 * RFM — столбцами, а не полосами: сегменты упорядочены от лучших к потерянным,
 * и рядом стоящие столбцы читаются как шкала, по которой клиенты сползают.
 */
const segmentColumns = computed<Column[]>(() =>
  (customers.value?.segments ?? []).map(segment => ({
    // «1. Лучшие» → «Лучшие»: номер задаёт порядок, а не подпись.
    label: segment.segment.replace(/^\d+\.\s*/, ''),
    value: segment.revenue,
    detail: `${formatNumber(segment.customers)} клиентов · ${formatCompactMoney(segment.revenue_per_customer)} на клиента`,
  })),
)

const cohortColumns = computed<Column[]>(() =>
  [...(customers.value?.cohorts ?? [])].reverse().map(cohort => ({
    label: cohort.cohort,
    value: cohort.revenue,
    detail: `${formatNumber(cohort.customers)} клиентов · ${formatCompactMoney(cohort.revenue_per_customer)} на клиента`,
  })),
)

/*
 * Откуда выручка — кольцом: четыре источника, которые в сумме дают целое, и
 * вопрос здесь про доли, а не про рейтинг. Возврат в кольцо не попадает — его
 * сумма отрицательна, а отрицательной доли круга не бывает; он назван цифрой
 * рядом.
 */
const orderTypeSlices = computed<DonutSlice[]>(() =>
  (customers.value?.order_types ?? []).map(row => ({
    name: row.type,
    value: row.revenue,
  })),
)

const returned = computed(() =>
  (customers.value?.order_types ?? []).filter(row => row.revenue < 0),
)

const topRows = computed<BarRow[]>(() =>
  (customers.value?.top ?? []).map(row => ({
    name: row.name,
    value: row.revenue,
    meta: `${row.rfm} · за всё время ${formatCompactMoney(row.ltv)} и ${formatNumber(row.lifetime_orders)} заказов`,
  })),
)

/*
 * Оборот против маржи: рейтинг отвечает, кто принёс больше, и молчит о том,
 * чего это стоило. Крупный клиент с маржой вдвое ниже средней — обычное дело
 * и повод для разговора, но увидеть его можно только на двух осях сразу.
 */
const topPoints = computed<ScatterPoint[]>(() =>
  (customers.value?.top ?? [])
    .filter(row => row.revenue > 0)
    .map(row => ({
      name: row.name,
      x: row.revenue,
      y: row.margin,
      weight: row.lifetime_orders,
    })),
)

const averageMargin = computed(() => {
  const totals = current.value

  return totals && totals.revenue > 0
    ? Number((totals.profit / totals.revenue * 100).toFixed(1))
    : undefined
})
</script>

<template>
  <div class="page">
    <header class="page__head">
      <h1 class="page__title">
        Клиенты
      </h1>
      <p class="page__lead">
        Кто покупает, кто вернулся и кто перестал. Розница без карты из подсчёта исключена:
        витрина сводит её на одного технического контрагента, и оставленная в списке,
        она возглавила бы его, не будучи клиентом.
      </p>
    </header>

    <AnalyticsFilterBar v-model="filters" :directory="directory" />
    <AnalyticsFreshnessNote :freshness="directory?.freshness ?? []" :sources="['sales']" />

    <p v-if="error" class="notice notice--error">
      {{ message }}
    </p>

    <AnalyticsBentoGrid v-else-if="current && customers">
      <AnalyticsStatTile
        label="Клиентов"
        :value="current.customers"
        :previous="previous?.customers"
      />
      <AnalyticsStatTile
        label="Впервые"
        :value="current.new_customers"
        :previous="previous?.new_customers"
        :hint="`${formatPercent(current.new_share)} всех клиентов`"
      />
      <AnalyticsStatTile
        label="Вернулись после паузы"
        :value="current.revived_customers"
        :previous="previous?.revived_customers"
      />
      <AnalyticsStatTile
        label="Выручка на клиента"
        :value="current.revenue_per_customer"
        :previous="previous?.revenue_per_customer"
        format="money"
        :precise="formatMoney(current.revenue_per_customer, 2)"
      />
      <AnalyticsStatTile
        label="Заказов на клиента"
        :value="current.orders_per_customer"
        :previous="previous?.orders_per_customer"
        format="decimal"
      />
      <AnalyticsStatTile
        label="Выручка"
        :value="current.revenue"
        :previous="previous?.revenue"
        format="money"
        hint="без розницы по карте"
      />

      <AnalyticsChartCard
        title="RFM"
        hint="Давность, частота и деньги, сведённые витриной на всей истории клиента, а не на выбранном периоде."
        :span="8"
        :rows="3"
      >
        <AnalyticsColumnChart :columns="segmentColumns" format="money" label-all :height="300" />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Откуда выручка"
        hint="Новый клиент, повторный, вернувшийся после паузы."
        :span="4"
        :rows="3"
      >
        <div class="sources">
          <AnalyticsDonutChart
            :slices="orderTypeSlices"
            center-label="без возвратов"
            :size="160"
          />

          <p v-for="row in returned" :key="row.type" class="sources__note">
            {{ row.type }} — {{ formatCompactMoney(row.revenue) }}
            за {{ formatNumber(row.orders) }} заказов. В кольце их нет:
            доля круга не бывает отрицательной.
          </p>
        </div>
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Когорты"
        hint="Месяц, в который клиент пришёл впервые. Показаны только надёжные — те, чей первый заказ витрина видела своими глазами."
        :span="12"
        :rows="2"
      >
        <AnalyticsColumnChart :columns="cohortColumns" format="money" :height="200" />
      </AnalyticsChartCard>

      <!-- Список и график стоят в одном ряду и обязаны совпасть по высоте:
           пятнадцать строк вдвое выше графика, поэтому список прокручивается
           внутри своей панели, а не растягивает соседнюю. -->
      <AnalyticsChartCard
        title="Крупнейшие клиенты"
        hint="Полоса — выручка за период; в подписи то, что витрина знает о клиенте за всю его жизнь."
        :span="6"
        :rows="4"
        scroll
      >
        <AnalyticsBarList :rows="topRows" />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Оборот и маржа"
        hint="Те же клиенты на двух осях сразу. Правый нижний угол — крупный оборот при низкой марже: то, ради чего график и смотрят."
        :span="6"
        :rows="4"
      >
        <AnalyticsScatterPlot :points="topPoints" :benchmark="averageMargin" :height="320" />
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

.sources {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.9rem;
}

.sources__note {
  margin: 0;
  font-size: 0.75rem;
  line-height: 1.45;
  color: var(--color-text-faint);
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