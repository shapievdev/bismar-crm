<script setup lang="ts">
import type { BarRow } from '~/components/analytics/BarList.vue'
import type { ScatterPoint } from '~/components/analytics/ScatterPlot.vue'
import { formatCompactMoney, formatMoney, formatNumber, formatPercent } from '~/utils/numbers'

/**
 * Продажи по сотрудникам.
 *
 * Та же выручка, что на вкладке продаж, но знаменателем человек: не «сколько
 * наторговали», а «что происходит с теми, кто торгует». Поэтому здесь нет ни
 * динамики, ни каналов — они отвечают на вопрос соседней вкладки, — зато есть
 * средний заказ каждого и то, скольких клиентов он за период вёл.
 *
 * Рейтинг приезжает целиком, а не верхушкой: отчёт по людям читают не только
 * сверху, и человек, открывший его, ищет в нём себя. Из того же списка собраны
 * и график, и оба боковых среза — второго запроса они не стоят.
 */
definePageMeta({ middleware: 'auth', permission: 'analytics.view' })

useHead({ title: 'Сотрудники — Аналитика' })

const filters = useAnalyticsFilters()
const { data: directory } = useAnalyticsDirectory()
const { fetchEmployees } = useAnalyticsApi()

const { data: response, pending, error } = await useAsyncData(
  'analytics-employees',
  () => fetchEmployees(filters.value),
  { watch: [filters] },
)

/**
 * Текст отказа: сервер аналитики объясняет причину сам, и его формулировка
 * точнее общей — она отличает недоступность от отказа в правах.
 */
const message = computed(() =>
  (error.value?.data as { message?: string } | undefined)?.message
  ?? 'Сервер аналитики недоступен. Цифры появятся, когда связь восстановится.',
)

const employees = computed(() => response.value?.data)
const current = computed(() => employees.value?.summary.current)
const previous = computed(() => employees.value?.summary.previous)
const ranking = computed(() => employees.value?.ranking ?? [])

const rankRows = computed<BarRow[]>(() =>
  ranking.value.map(row => ({
    name: row.name,
    value: row.revenue,
    meta: `маржа ${formatPercent(row.margin)} · ${formatNumber(row.orders)} заказов · ${formatNumber(row.customers)} клиентов`,
    // Витрина знает себестоимость почти везде, но не всюду — там, где дыра
    // заметна, маржа перестаёт быть фактом.
    uncertain: row.without_cost_share > 20,
  })),
)

/*
 * Оборот против маржи: правый нижний угол — тот, кто много продаёт и мало
 * зарабатывает. Размер точки — число заказов, потому что один и тот же оборот
 * набирается и тридцатью накладными, и четырьмя тысячами чеков, и это разная
 * работа.
 */
const rankPoints = computed<ScatterPoint[]>(() =>
  ranking.value.map(row => ({
    name: row.name,
    x: row.revenue,
    y: row.margin,
    weight: row.orders,
  })),
)

/** Сколько строк показывают боковые срезы: дальше это уже не «кто лучший». */
const ASIDE_ROWS = 8

/*
 * Средний заказ — не производная от рейтинга, а свой вопрос. Первый по обороту
 * бывает первым и здесь, но чаще наоборот: крупные отгрузки идут редко, а касса
 * набирает ту же сумму тысячами мелких чеков.
 *
 * Берутся только те, у кого заказов хватает на среднее: по трём заказам среднее
 * — это не средний заказ, а один крупный, поделённый на три.
 */
const MIN_ORDERS_FOR_AVERAGE = 10

const averageOrderRows = computed<BarRow[]>(() =>
  [...ranking.value]
    .filter(row => row.orders >= MIN_ORDERS_FOR_AVERAGE)
    .sort((a, b) => b.average_order - a.average_order)
    .slice(0, ASIDE_ROWS)
    .map(row => ({
      name: row.name,
      value: row.average_order,
      meta: `${formatNumber(row.orders)} заказов на ${formatCompactMoney(row.revenue)}`,
    })),
)

const customerRows = computed<BarRow[]>(() =>
  [...ranking.value]
    .sort((a, b) => b.customers - a.customers)
    .slice(0, ASIDE_ROWS)
    .map(row => ({
      name: row.name,
      value: row.customers,
      meta: `${formatCompactMoney(row.revenue)} за период`,
    })),
)

/** Линия, относительно которой читается вертикаль графика. */
const averageMargin = computed(() => current.value?.margin ?? 0)
</script>

<template>
  <div class="page">
    <header class="page__head">
      <h1 class="page__title">
        Сотрудники
      </h1>
      <p class="page__lead">
        Та же торговля, но по людям. В чеке продажу делает продавец, в накладной — ответственный,
        и здесь они сведены в одно имя: порознь каждое поле теряет половину оборота. Кассы и
        учётные записи стоят в списке наравне с людьми — так продажа заведена в 1С.
      </p>
    </header>

    <AnalyticsFilterBar v-model="filters" :directory="directory" />
    <AnalyticsFreshnessNote :freshness="directory?.freshness ?? []" :sources="['sales']" />

    <p v-if="error" class="notice notice--error">
      {{ message }}
    </p>

    <AnalyticsBentoGrid v-else-if="current && employees">
      <AnalyticsStatTile
        label="Выручка"
        :value="current.revenue"
        :previous="previous?.revenue"
        format="money"
        :precise="formatMoney(current.revenue, 2)"
        :hint="current.revenue_unattributed !== 0
          ? `без сотрудника ещё ${formatCompactMoney(current.revenue_unattributed)}`
          : undefined"
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
        label="Торговало"
        :value="current.people"
        :previous="previous?.people"
        :hint="`${formatCompactMoney(current.revenue_per_person)} на человека`"
      />
      <AnalyticsStatTile
        label="Заказов"
        :value="current.orders"
        :previous="previous?.orders"
        :hint="`${formatNumber(current.orders_per_person)} на человека`"
      />
      <AnalyticsStatTile
        label="Средний заказ"
        :value="current.average_order"
        :previous="previous?.average_order"
        format="money"
        :precise="formatMoney(current.average_order, 2)"
      />

      <!-- Рейтинг и график стоят в одном ряду: первый отвечает «кто сколько»,
           второй — «какой ценой», и порознь они читаются хуже. Список длиннее
           графика, поэтому прокручивается внутри своей панели. -->
      <AnalyticsChartCard
        title="Кто сколько наторговал"
        hint="Весь список, а не первая десятка: отчёт по людям читают и снизу."
        :span="7"
        :rows="3"
        scroll
      >
        <AnalyticsBarList :rows="rankRows" />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Оборот и маржа"
        hint="Горизонталь — оборот, вертикаль — маржа, размер точки — число заказов. Правый нижний угол: продаёт много, зарабатывает мало."
        :span="5"
        :rows="3"
      >
        <AnalyticsScatterPlot :points="rankPoints" :benchmark="averageMargin" :height="300" />
      </AnalyticsChartCard>

      <!-- Рейтинги, а не столбцы: имя сотрудника под столбцом не помещается, и
           десять имён подряд слипаются в полосу, из которой не прочесть ни
           одного. Горизонтальная полоса оставляет имени целую строку. -->
      <AnalyticsChartCard
        title="Средний заказ"
        :hint="`Другой порядок, чем в рейтинге: крупные отгрузки идут редко, касса набирает ту же сумму тысячами чеков. Показаны те, у кого от ${MIN_ORDERS_FOR_AVERAGE} заказов.`"
        :span="6"
        :rows="3"
      >
        <AnalyticsBarList :rows="averageOrderRows" />
      </AnalyticsChartCard>

      <AnalyticsChartCard
        title="Клиентов за период"
        hint="Считаются названные: покупка без карты приходит без контрагента, и за ней не стоит клиент, которого можно посчитать."
        :span="6"
        :rows="3"
      >
        <AnalyticsBarList :rows="customerRows" format="number" />
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
