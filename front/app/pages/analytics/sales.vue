<script setup lang="ts">
import type { BarRow } from '~/components/analytics/BarList.vue'
import type { Column } from '~/components/analytics/ColumnChart.vue'
import type { DonutSlice } from '~/components/analytics/DonutChart.vue'
import type { StackSlice } from '~/components/analytics/StackedBars.vue'
import type { TrendSeries } from '~/components/analytics/TrendChart.vue'
import type { SalesDimension } from '~/types/analytics'
import { formatCompactMoney, formatMoney, formatNumber, formatPercent } from '~/utils/numbers'

definePageMeta({ middleware: 'auth', permission: 'analytics.view' })

useHead({ title: 'Продажи — Аналитика' })

const filters = useAnalyticsFilters()
const { data: directory } = useAnalyticsDirectory()
const { fetchSales, fetchSalesBreakdown } = useAnalyticsApi()

/*
 * Ответ сохраняется целиком, а не одними цифрами: в `meta` лежит срез, в
 * котором сервер понял вопрос, — в том числе шаг, который он выбрал сам, когда
 * его не задали. По нему подписываются оси. Выведенный на клиенте по числу
 * точек, шаг однажды разойдётся с тем, по чему сгруппирован запрос.
 */
const { data: response, pending, error } = await useAsyncData(
  'analytics-sales',
  () => fetchSales(filters.value),
  { watch: [filters] },
)

/** Разрез рейтинга переключается отдельно от всей вкладки. */
const dimension = ref<SalesDimension>('item')

const { data: breakdown, pending: breakdownPending } = await useAsyncData(
  'analytics-sales-breakdown',
  async () => (await fetchSalesBreakdown(filters.value, dimension.value)).data,
  { watch: [filters, dimension] },
)

/**
 * Текст отказа: сервер аналитики объясняет причину сам, и его формулировка
 * точнее общей — она отличает недоступность от отказа в правах.
 */
const message = computed(() =>
  (error.value?.data as { message?: string } | undefined)?.message
  ?? 'Сервер аналитики недоступен. Цифры появятся, когда связь восстановится.',
)

const sales = computed(() => response.value?.data)
const current = computed(() => sales.value?.summary.current)
const previous = computed(() => sales.value?.summary.previous)

/** Шаг, которым сервер сгруппировал ряд, — а не тот, о котором просили. */
const step = computed(() => response.value?.meta?.period.granularity ?? 'day')

const revenueSeries: TrendSeries[] = [
  { key: 'revenue', label: 'Выручка', slot: 1, format: 'money' },
  { key: 'profit', label: 'Валовая прибыль', slot: 2, format: 'money' },
]

/*
 * Сравнение с предыдущим отрезком — отдельный график, а не вторая линия на
 * первом: там уже лежит прибыль, и третья линия превратила бы его в клубок.
 * Точки сопоставлены по номеру дня внутри периода, поэтому подпись у них —
 * дата текущего периода, а шаг всегда дневной.
 */
const comparisonSeries: TrendSeries[] = [
  { key: 'current', label: 'Текущий период', slot: 1, format: 'money' },
  { key: 'previous', label: 'Предыдущий', slot: 2, format: 'money' },
]

const comparisonPoints = computed(() =>
  (sales.value?.comparison ?? []).map(point => ({
    bucket: point.label,
    current: point.current,
    previous: point.previous,
  })),
)

/** Столбец — период, доли внутри него — каналы. */
const channelStack = computed<StackSlice[]>(() =>
  (sales.value?.channel_trend ?? []).map(slice => ({
    bucket: slice.bucket,
    name: slice.channel,
    value: slice.revenue,
  })),
)

const channelSlices = computed<DonutSlice[]>(() =>
  (sales.value?.channels ?? []).map(channel => ({
    name: channel.channel,
    value: channel.revenue,
  })),
)

const weekdayColumns = computed<Column[]>(() =>
  (sales.value?.weekday ?? []).map(day => ({
    label: day.label,
    value: day.revenue,
    detail: `${formatNumber(day.orders)} заказов в средний такой день`,
  })),
)

const rankRows = computed<BarRow[]>(() =>
  (breakdown.value ?? []).map(row => ({
    name: row.name,
    value: row.revenue,
    meta: `маржа ${formatPercent(row.margin)} · ${formatNumber(row.orders)} заказов`,
    // Витрина знает себестоимость почти везде, но не всюду — там, где дыра
    // заметна, маржа перестаёт быть фактом.
    uncertain: row.without_cost_share > 20,
  })),
)
</script>

<template>
  <div class="page">
    <header class="page__head">
      <h1 class="page__title">
        Продажи
      </h1>
      <p class="page__lead">
        Отгрузки и розница за выбранный период. Заказ считается целиком, а не построчно:
        средний заказ — это выручка на заказ, а не на позицию в нём.
      </p>
    </header>

    <AnalyticsFilterBar v-model="filters" :directory="directory" />
    <AnalyticsFreshnessNote :freshness="directory?.freshness ?? []" :sources="['sales']" />

    <p v-if="error" class="notice notice--error">
      {{ message }}
    </p>

    <AnalyticsBentoGrid v-else-if="current && sales">
      <AnalyticsStatTile
        label="Выручка"
        :value="current.revenue"
        :previous="previous?.revenue"
        format="money"
        :precise="formatMoney(current.revenue, 2)"
      />
      <AnalyticsStatTile
        label="Валовая прибыль"
        :value="current.profit"
        :previous="previous?.profit"
        format="money"
        :precise="formatMoney(current.profit, 2)"
      />
      <AnalyticsStatTile
        label="Маржа"
        :value="current.margin"
        :previous="previous?.margin"
        format="percent"
        :hint="`себестоимость неизвестна у ${formatPercent(current.without_cost_share)} выручки`"
      />
      <AnalyticsStatTile
        label="Заказов"
        :value="current.orders"
        :previous="previous?.orders"
      />
      <AnalyticsStatTile
        label="Средний заказ"
        :value="current.average_order"
        :previous="previous?.average_order"
        format="money"
        :precise="formatMoney(current.average_order, 2)"
      />
      <AnalyticsStatTile
        label="Возвраты"
        :value="current.returns"
        :previous="previous?.returns"
        format="money"
        growth="bad"
        :hint="`${formatPercent(current.return_rate)} выручки`"
      />

      <!-- Главный график занимает две трети ширины и три ряда: ради него
           вкладку и открывают, а остальное объясняет его форму. -->
      <AnalyticsChartCard
        title="Выручка и прибыль"
        hint="Заштриховано то, что лежит между ними, — себестоимость проданного."
        :span="8"
        :rows="3"
      >
        <AnalyticsTrendChart
          :points="sales.trend"
          :series="revenueSeries"
          :granularity="step"
          :height="330"
        />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Из чего складывается прибыль"
        hint="Выручка приходит, себестоимость её съедает, остаётся валовая прибыль."
        :span="4"
        :rows="3"
      >
        <AnalyticsWaterfallChart
          :revenue="sales.cost_structure.revenue"
          :cost="sales.cost_structure.cost"
          :profit="sales.cost_structure.profit"
          :margin="sales.cost_structure.margin"
          :height="290"
        />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Против прошлого периода"
        hint="Отрезок такой же длины, наложенный по номеру дня, а не по календарю: у месяцев разное число дней."
        :span="7"
        :rows="2"
      >
        <AnalyticsTrendChart
          :points="comparisonPoints"
          :series="comparisonSeries"
          granularity="day"
          :area="false"
          :height="195"
        />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Доли каналов"
        hint="Накладные и чеки ККМ живут по разным законам: у первых заказ крупнее, у вторых их больше."
        :span="5"
        :rows="2"
      >
        <div class="channels">
          <!-- Итог кольцо печатает само; подпись под ним объясняет, что это за
               число, а не повторяет его другим. -->
          <AnalyticsDonutChart
            :slices="channelSlices"
            center-label="за период"
            :size="150"
          />

          <dl class="channels__facts">
            <div v-for="channel in sales.channels" :key="channel.channel" class="fact">
              <dt class="fact__name">
                {{ channel.channel }}
              </dt>
              <dd class="fact__meta">
                маржа {{ formatPercent(channel.margin) }} ·
                средний заказ {{ formatCompactMoney(channel.average_order) }}
              </dd>
            </div>
          </dl>
        </div>
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Структура выручки по каналам"
        hint="Высота столбца — выручка периода, доли внутри — каналы. Вопрос не «сколько», а «менялось ли соотношение»."
        :span="7"
        :rows="2"
      >
        <AnalyticsStackedBars :slices="channelStack" :granularity="step" :height="195" />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Профиль недели"
        hint="В среднем за один такой день, а не суммой: вторников в периоде может быть на один больше, чем понедельников."
        :span="5"
        :rows="2"
      >
        <AnalyticsColumnChart :columns="weekdayColumns" format="money" label-all :height="195" />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Рейтинг"
        hint="Топ по выручке за период."
        :span="12"
        :rows="2"
      >
        <template #actions>
          <div class="switch">
            <button
              v-for="option in directory?.dimensions ?? []"
              :key="option.value"
              type="button"
              class="switch__item"
              :class="{ 'switch__item--active': dimension === option.value }"
              @click="dimension = option.value as SalesDimension"
            >
              {{ option.label }}
            </button>
          </div>
        </template>

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

  /* Шаг сетки задаётся страницей: панели объявляют размер в рядах, а высоту
     ряда держит весь дашборд — иначе «две высоты» означали бы разное на
     соседних вкладках. */
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

/* Кольцо и подписи рядом: доля читается вдвое легче, когда цифра, из которой
   она взята, стоит на расстоянии взгляда. */
.channels {
  display: flex;
  align-items: center;
  gap: 1.25rem;
  flex-wrap: wrap;
}

.channels__facts {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  margin: 0;
  min-width: 0;
  flex: 1;
}

.fact__name {
  margin: 0;
  font-size: 0.82rem;
  font-weight: 600;
}

.fact__meta {
  margin: 0.1rem 0 0;
  font-size: 0.75rem;
  color: var(--color-text-faint);
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