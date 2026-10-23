<script setup lang="ts">
import type { ComposedDashboard, ComposedPanel } from '~/types/analytics'

/**
 * Дашборд, собранный ИИ-аналитиком.
 *
 * Рисует он теми же компонентами, что и вкладки аналитики, — своих графиков
 * здесь нет ни одного, и это главное свойство раздела: панель, собранная по
 * вопросу, выглядит и считается ровно как панель, выбранная руками. Модель
 * выбирает только, какие детали взять и в каком порядке.
 *
 * Сервер присылает панели уже посчитанными и в том виде, в каком их ждут
 * компоненты: плитка — число и прошлое, график — точки и серии, рейтинг — имя
 * со значением. Пересобирать это здесь было бы вторым местом, где знают, что
 * такое «маржа», и однажды эти два места разошлись бы (см. DashboardExecutor).
 */
const props = defineProps<{ dashboard: ComposedDashboard }>()

/** Период, за который собрано, — одной строкой под заголовком. */
const period = computed(() => {
  const slice = props.dashboard.slice

  if (!slice?.period) {
    return null
  }

  return `${formatDate(slice.period.from)} — ${formatDate(slice.period.to)}`
})

/** Чем отобрано, если отобрано: человеку надо знать, что цифры не обо всём. */
const narrowedBy = computed(() => {
  const slice = props.dashboard.slice

  if (!slice) {
    return []
  }

  return [
    ...(slice.warehouses ?? []),
    ...(slice.channels ?? []),
    ...(slice.managers ?? []),
    ...(slice.segments ?? []),
  ]
})

function formatDate(value: string): string {
  const [year, month, day] = value.split('-')

  return `${day}.${month}.${year}`
}

/** Полосы и столбцы ждут своих имён полей — приводим на месте. */
function bars(panel: ComposedPanel) {
  return (panel.items ?? []).map(item => ({
    name: item.name,
    value: item.value,
    meta: item.meta,
    uncertain: item.uncertain,
  }))
}

function columns(panel: ComposedPanel) {
  return (panel.items ?? []).map(item => ({
    label: item.name,
    value: item.value,
    detail: item.meta,
  }))
}

function slices(panel: ComposedPanel) {
  return (panel.items ?? []).map(item => ({ name: item.name, value: item.value }))
}
</script>

<template>
  <section class="composed">
    <header class="composed__head">
      <h3 class="composed__title">
        {{ dashboard.title }}
      </h3>

      <p v-if="period" class="composed__period">
        {{ period }}
        <span v-if="narrowedBy.length">· только {{ narrowedBy.join(', ') }}</span>
      </p>
    </header>

    <AnalyticsBentoGrid>
      <template v-for="(panel, index) in dashboard.panels" :key="index">
        <AnalyticsStatTile
          v-if="panel.type === 'kpi'"
          :label="panel.label ?? ''"
          :value="panel.value ?? 0"
          :previous="panel.previous"
          :format="panel.format"
          :growth="panel.growth"
          :hint="panel.hint"
          :span="panel.span"
        />

        <AnalyticsChartCard
          v-else
          :title="panel.title ?? ''"
          :span="panel.span"
          :rows="panel.rows"
          :scroll="panel.type === 'bars'"
        >
          <!--
            Пустая панель остаётся на месте и говорит об этом прямо: исчезнув,
            она разошлась бы с ответом словами, который её пообещал, — и человек
            решил бы, что приложение сломалось.
          -->
          <p v-if="!panel.items?.length && panel.type !== 'line'" class="composed__empty">
            За этот срез продаж нет.
          </p>

          <AnalyticsTrendChart
            v-else-if="panel.type === 'line'"
            :points="panel.points ?? []"
            :series="panel.series ?? []"
            :granularity="panel.granularity"
            :height="panel.rows && panel.rows > 2 ? 330 : 240"
          />

          <AnalyticsBarList
            v-else-if="panel.type === 'bars'"
            :rows="bars(panel)"
            :format="panel.format === 'money' ? 'money' : 'number'"
          />

          <AnalyticsColumnChart
            v-else-if="panel.type === 'columns'"
            :columns="columns(panel)"
            :format="panel.format === 'money' ? 'money' : 'number'"
            label-all
          />

          <AnalyticsDonutChart
            v-else-if="panel.type === 'donut'"
            :slices="slices(panel)"
          />
        </AnalyticsChartCard>
      </template>
    </AnalyticsBentoGrid>
  </section>
</template>

<style scoped>
.composed {
  margin-top: 0.75rem;
}

.composed__head {
  margin-bottom: 0.6rem;
}

.composed__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 500;
}

.composed__period {
  margin: 0.15rem 0 0;
  color: var(--color-text-muted);
  font-size: 0.82rem;
}

.composed__empty {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.88rem;
}
</style>
