<script setup lang="ts">
import type { DepartmentSalesRow, DepartmentSeller } from '~/types/analytics'
import type { SalesPlan, SalesPlanKind } from '~/composables/useSalesApi'
import { formatCompactMoney, formatMoney, formatNumber, formatPercent } from '~/utils/numbers'

/**
 * План и факт по отделам.
 *
 * Период здесь не такой, как на соседних вкладках, и это не недосмотр: планы
 * ставят на календарный месяц, и сравнивать их с «последними тридцатью днями»
 * нечем. Открывается текущий месяц с первого числа по сегодня, переключается на
 * любой прошлый.
 *
 * Отсюда же и главная величина экрана. Факт шестнадцатого числа против плана на
 * все тридцать один всегда покажет отставание, которого нет, — поэтому рядом с
 * выполнением стоит темп: доля плана, которая к сегодняшнему дню должна быть
 * сделана, если идти ровно.
 *
 * План правится прямо здесь, а не в настройках. Ставят его, глядя на факт, — и
 * экран, где одно без другого, заставлял бы держать цифру в голове, переходя
 * туда и обратно.
 */
definePageMeta({ middleware: 'auth', permission: 'analytics.view' })

useHead({ title: 'Отделы — Аналитика' })

const { can } = useAuth()
const { fetchDepartmentSales } = useAnalyticsApi()
const { fetchPlans, savePlan, deletePlan, suggestPlans } = useSalesApi()

const mayPlan = computed(() => can('sales-plans.manage'))

/* ---------- Месяц ---------- */

/** Последние два года помесячно: дальше витрина всё равно не достаёт. */
const months = computed(() => {
  const now = new Date()
  const list: { value: string, label: string }[] = []

  for (let back = 0; back < 24; back++) {
    const date = new Date(now.getFullYear(), now.getMonth() - back, 1)
    const value = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`

    list.push({
      value,
      label: date.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }),
    })
  }

  return list
})

const month = ref(months.value[0]!.value)

const { data: response, pending, error, refresh } = await useAsyncData(
  'analytics-departments',
  () => fetchDepartmentSales(month.value),
  { watch: [month] },
)

const report = computed(() => response.value?.data)
const totals = computed(() => report.value?.totals)
const period = computed(() => report.value?.period)

const message = computed(() =>
  (error.value?.data as { message?: string } | undefined)?.message
  ?? 'Сервер аналитики недоступен. Цифры появятся, когда связь восстановится.',
)

/* ---------- Планы ---------- */

/*
 * Планы приезжают отдельно от отчёта и только тому, кто вправе их ставить.
 * В отчёте план есть и так — числом; здесь нужен его номер, чтобы снятый план
 * можно было удалить, а не записать нулём: ноль — тоже требование.
 */
const { data: plans, refresh: refreshPlans } = await useAsyncData<SalesPlan[]>(
  'sales-plans',
  async () => (mayPlan.value ? (await fetchPlans(month.value)).data : []),
  { watch: [month] },
)

function planId(kind: SalesPlanKind, departmentId: number | null, personId: number | null): number | null {
  return (plans.value ?? []).find(plan =>
    plan.kind === kind
    && plan.department_id === departmentId
    && plan.sales_person_id === personId,
  )?.id ?? null
}

const saving = ref<string | null>(null)

/**
 * Записать то, что ввели в клетку плана.
 *
 * Пустая клетка — снять план, а не поставить ноль. Разница существенная:
 * «плана нет» и «план нулевой» читаются в отчёте по-разному, и второе —
 * осознанное решение не ждать с отдела ничего.
 */
async function commit(
  cell: string,
  kind: SalesPlanKind,
  target: { department_id?: number | null, sales_person_id?: number | null },
  raw: string,
) {
  const value = raw.trim().replace(/\s/g, '').replace(',', '.')
  const amount = value === '' ? null : Number(value)

  if (amount !== null && (Number.isNaN(amount) || amount < 0)) {
    return
  }

  saving.value = cell

  try {
    if (amount === null) {
      const id = planId(kind, target.department_id ?? null, target.sales_person_id ?? null)

      if (id !== null) {
        await deletePlan(id)
      }
    }
    else {
      await savePlan({ period: month.value, kind, ...target, revenue: amount })
    }

    await Promise.all([refresh(), refreshPlans()])
  }
  finally {
    saving.value = null
  }
}

/*
 * Планы по умолчанию — из факта закрытых месяцев.
 *
 * Отдельным нажатием, а не само при открытии месяца: это мерка, по которой
 * потом спросят, и ставить её молча нельзя. Поставленное руками не
 * переписывается, поэтому нажать можно и повторно — доставит только
 * недостающее.
 */
const { confirm } = useAppDialog()

const suggesting = ref(false)

async function placeDefaults() {
  const agreed = await confirm({
    title: 'Расставить планы по умолчанию?',
    message: 'За план возьмём среднюю выручку трёх закрытых месяцев: отделу — его собственную, продавцу — свою же, новичку — средний по отделу. Уже поставленное останется как есть.',
    confirmLabel: 'Расставить',
  })

  if (!agreed) {
    return
  }

  suggesting.value = true

  try {
    await suggestPlans(month.value)
    await Promise.all([refresh(), refreshPlans()])
  }
  finally {
    suggesting.value = false
  }
}

/* ---------- Развёрнутые отделы ---------- */

const opened = ref<number[]>([])

function toggle(id: number) {
  opened.value = opened.value.includes(id)
    ? opened.value.filter(item => item !== id)
    : [...opened.value, id]
}

/** Как читать выполнение: по темпу в текущем месяце, по плану в прошедшем. */
function tone(row: DepartmentSalesRow | DepartmentSeller): 'good' | 'bad' | 'muted' {
  const against = period.value?.is_current ? row.pace_completion : row.completion

  if (against === null || against === undefined) {
    return 'muted'
  }

  return against >= 100 ? 'good' : 'bad'
}

function completionOf(row: DepartmentSalesRow | DepartmentSeller): number | null {
  return period.value?.is_current ? row.pace_completion : row.completion
}

const planSourceLabel: Record<string, string> = {
  person: 'личный',
  member: 'норматив отдела',
}
</script>

<template>
  <div class="page">
    <header class="page__head">
      <h1 class="page__title">
        Отделы
      </h1>
      <p class="page__lead">
        План и факт по структуре компании. Выручка раскладывается по отделам через сопоставление
        продавцов витрины; чей отдел не назван, тот стоит отдельной строкой внизу.
      </p>
    </header>

    <div class="toolbar">
      <UiSelect v-model="month" :options="months" auto />

      <button
        v-if="mayPlan"
        type="button"
        class="defaults"
        :disabled="suggesting"
        @click="placeDefaults"
      >
        {{ suggesting ? 'Считаем…' : 'Расставить по прошлым месяцам' }}
      </button>

      <span v-if="period" class="toolbar__note">
        <template v-if="period.is_current">
          с 1-го по {{ period.days_elapsed }}-е — прошло {{ period.days_elapsed }} из
          {{ period.days_in_month }} дней
        </template>
        <template v-else>
          месяц закрыт целиком
        </template>
      </span>
    </div>

    <p v-if="error" class="notice notice--error">
      {{ message }}
    </p>

    <template v-else-if="report && totals">
      <section class="summary">
        <div class="summary__tile">
          <span class="summary__label">Выручка</span>
          <strong class="summary__value">{{ formatCompactMoney(totals.revenue) }}</strong>
          <span class="summary__hint">{{ formatMoney(totals.revenue) }}</span>
        </div>
        <div class="summary__tile">
          <span class="summary__label">План отделов</span>
          <strong class="summary__value">
            {{ totals.plan === null ? '—' : formatCompactMoney(totals.plan) }}
          </strong>
          <span class="summary__hint">
            факт тех же отделов — {{ formatCompactMoney(totals.planned_revenue) }}
          </span>
        </div>
        <div class="summary__tile">
          <span class="summary__label">{{ period?.is_current ? 'К темпу' : 'Выполнение' }}</span>
          <strong class="summary__value" :class="`summary__value--${tone(totals as never)}`">
            {{ completionOf(totals as never) === null ? '—' : formatPercent(completionOf(totals as never)!) }}
          </strong>
          <span v-if="period?.is_current && totals.pace !== null" class="summary__hint">
            к этому дню ждали {{ formatCompactMoney(totals.pace) }}
          </span>
          <span v-else-if="totals.plan === null" class="summary__hint">план не поставлен</span>
        </div>
        <div class="summary__tile" :class="{ 'summary__tile--warn': totals.unassigned_share > 0 }">
          <span class="summary__label">Не распределено</span>
          <strong class="summary__value">{{ formatCompactMoney(totals.unassigned_revenue) }}</strong>
          <span class="summary__hint">{{ formatPercent(totals.unassigned_share) }} выручки</span>
        </div>
      </section>

      <p v-if="pending" class="loading">
        Считаем…
      </p>

      <div class="table" role="table">
        <div class="row row--head" role="row">
          <span class="cell cell--name">Отдел</span>
          <span class="cell cell--num">Факт</span>
          <span class="cell cell--num">План на месяц</span>
          <span class="cell cell--num">{{ period?.is_current ? 'К темпу' : 'Выполнение' }}</span>
          <span class="cell cell--num">Норматив продавца</span>
          <span class="cell cell--num">Роздано</span>
        </div>

        <template v-for="row in report.departments" :key="row.id">
          <div class="row" role="row">
            <button
              type="button"
              class="cell cell--name name"
              :style="{ '--depth': row.depth }"
              :disabled="row.sellers.length === 0"
              @click="toggle(row.id)"
            >
              <span class="name__caret" :class="{ 'name__caret--open': opened.includes(row.id) }">
                {{ row.sellers.length > 0 ? '▸' : '' }}
              </span>
              <span class="name__text">{{ row.name }}</span>
              <span v-if="row.sellers.length > 0" class="name__count">
                {{ formatNumber(row.sellers.length) }}
              </span>
            </button>

            <span class="cell cell--num">
              {{ formatCompactMoney(row.revenue) }}
              <small v-if="row.own_revenue !== row.revenue" class="sub">
                своя {{ formatCompactMoney(row.own_revenue) }}
              </small>
            </span>

            <span class="cell cell--num">
              <input
                v-if="mayPlan"
                class="plan"
                :value="row.plan === null ? '' : formatNumber(row.plan)"
                :disabled="saving === `d${row.id}`"
                inputmode="decimal"
                placeholder="—"
                @change="commit(`d${row.id}`, 'department', { department_id: row.id }, ($event.target as HTMLInputElement).value)"
                @keyup.enter="($event.target as HTMLInputElement).blur()"
              >
              <template v-else>{{ row.plan === null ? '—' : formatCompactMoney(row.plan) }}</template>
            </span>

            <span class="cell cell--num" :class="`cell--${tone(row)}`">
              {{ completionOf(row) === null ? '—' : formatPercent(completionOf(row)!) }}
            </span>

            <span class="cell cell--num">
              <input
                v-if="mayPlan"
                class="plan"
                :value="row.member_plan === null ? '' : formatNumber(row.member_plan)"
                :disabled="saving === `m${row.id}`"
                inputmode="decimal"
                placeholder="—"
                @change="commit(`m${row.id}`, 'member', { department_id: row.id }, ($event.target as HTMLInputElement).value)"
                @keyup.enter="($event.target as HTMLInputElement).blur()"
              >
              <template v-else>{{ row.member_plan === null ? '—' : formatCompactMoney(row.member_plan) }}</template>
            </span>

            <span class="cell cell--num sub">
              {{ row.sellers_plan > 0 ? formatCompactMoney(row.sellers_plan) : '—' }}
            </span>
          </div>

          <div
            v-for="seller in (opened.includes(row.id) ? row.sellers : [])"
            :key="`${row.id}-${seller.external_name}`"
            class="row row--seller"
            role="row"
          >
            <span class="cell cell--name seller" :style="{ '--depth': row.depth + 1 }">
              <span class="seller__name">{{ seller.user_name ?? seller.external_name }}</span>
              <small v-if="seller.user_name" class="sub">{{ seller.external_name }}</small>
            </span>

            <span class="cell cell--num">
              {{ formatCompactMoney(seller.revenue) }}
              <small class="sub">{{ formatNumber(seller.orders) }} заказов</small>
            </span>

            <span class="cell cell--num">
              <input
                v-if="mayPlan && seller.sales_person_id !== null"
                class="plan"
                :value="seller.plan_source === 'person' && seller.plan !== null ? formatNumber(seller.plan) : ''"
                :disabled="saving === `p${seller.sales_person_id}`"
                inputmode="decimal"
                :placeholder="seller.plan_source === 'member' ? formatNumber(seller.plan ?? 0) : '—'"
                @change="commit(`p${seller.sales_person_id}`, 'person', { sales_person_id: seller.sales_person_id }, ($event.target as HTMLInputElement).value)"
                @keyup.enter="($event.target as HTMLInputElement).blur()"
              >
              <template v-else>{{ seller.plan === null ? '—' : formatCompactMoney(seller.plan) }}</template>
              <small v-if="seller.plan_source" class="sub">{{ planSourceLabel[seller.plan_source] }}</small>
            </span>

            <span class="cell cell--num" :class="`cell--${tone(seller)}`">
              {{ completionOf(seller) === null ? '—' : formatPercent(completionOf(seller)!) }}
            </span>

            <span class="cell cell--num" />
            <span class="cell cell--num" />
          </div>
        </template>
      </div>

      <section v-if="report.unassigned.length > 0" class="unassigned">
        <h2 class="unassigned__title">
          Не распределено
        </h2>
        <p class="unassigned__lead">
          Эти имена из витрины ещё не привязаны к отделу, и их выручка не попала ни в одну строку
          выше. Разбираются они в
          <NuxtLink to="/settings/sales">
            настройках
          </NuxtLink>.
        </p>

        <ul class="unassigned__list">
          <li v-for="seller in report.unassigned" :key="seller.external_name" class="unassigned__row">
            <span>{{ seller.external_name }}</span>
            <span class="unassigned__money">{{ formatCompactMoney(seller.revenue) }}</span>
          </li>
        </ul>
      </section>
    </template>

    <p v-else-if="pending" class="loading">
      Считаем…
    </p>
  </div>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.page__head {
  margin-bottom: 0.25rem;
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
  max-width: 66ch;
}

.toolbar {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  flex-wrap: wrap;
}

.toolbar__note {
  font-size: 0.78rem;
  color: var(--color-text-faint);
}

.defaults {
  padding: 0.35rem 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  font-size: 0.78rem;
  cursor: pointer;
}

.defaults:hover:not(:disabled) {
  border-color: var(--color-text-faint);
}

.defaults:disabled {
  opacity: 0.5;
  cursor: default;
}

.summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: 0.75rem;
}

.summary__tile {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  padding: 0.85rem 1rem;
  border-radius: var(--radius-md);
  background: var(--color-surface);
}

.summary__label {
  font-size: 0.74rem;
  color: var(--color-text-faint);
}

.summary__value {
  font-size: 1.25rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.summary__value--good {
  color: var(--color-success);
}

.summary__value--bad {
  color: var(--color-danger);
}

.summary__hint {
  font-size: 0.72rem;
  color: var(--color-text-faint);
}

.summary__tile--warn .summary__value {
  color: var(--color-danger);
}

/* Таблица, а не сетка панелей: план и факт читают построчно и сравнивают
   глазами по вертикали — столбец должен быть столбцом. */
.table {
  display: flex;
  flex-direction: column;
  border-radius: var(--radius-md);
  background: var(--color-surface);
  overflow-x: auto;
}

.row {
  display: grid;
  grid-template-columns: minmax(12rem, 2fr) repeat(5, minmax(6.5rem, 1fr));
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.85rem;
  min-width: 46rem;
  border-top: 1px solid var(--color-border);
}

.row:first-child {
  border-top: 0;
}

.row--head {
  font-size: 0.72rem;
  color: var(--color-text-faint);
}

.row--seller {
  background: var(--color-surface-sunken);
}

.cell {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
  font-size: 0.82rem;
}

.cell--num {
  align-items: flex-end;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.cell--good {
  color: var(--color-success);
}

.cell--bad {
  color: var(--color-danger);
}

.cell--muted {
  color: var(--color-text-faint);
}

.name {
  flex-direction: row;
  align-items: center;
  gap: 0.4rem;
  padding: 0;
  padding-left: calc(var(--depth, 0) * 0.9rem);
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  font-size: 0.82rem;
  text-align: left;
  cursor: pointer;
}

.name:disabled {
  cursor: default;
}

.name__caret {
  display: inline-block;
  width: 0.7rem;
  color: var(--color-text-faint);
  transition: transform 0.12s ease;
}

.name__caret--open {
  transform: rotate(90deg);
}

.name__text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.name__count {
  font-size: 0.7rem;
  color: var(--color-text-faint);
}

.seller {
  padding-left: calc(var(--depth, 0) * 0.9rem + 0.7rem);
}

.seller__name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sub {
  font-size: 0.7rem;
  color: var(--color-text-faint);
}

/* Поле плана выглядит текстом, пока в него не целятся: таблица из двадцати
   рамок читается как форма, а это отчёт, в котором иногда правят. */
.plan {
  width: 100%;
  padding: 0.15rem 0.3rem;
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 0.82rem;
  font-variant-numeric: tabular-nums;
  text-align: right;
}

.plan:hover:not(:disabled) {
  border-color: var(--color-border);
}

.plan:focus {
  border-color: var(--color-text-faint);
  outline: none;
}

.plan:disabled {
  opacity: 0.5;
}

.unassigned {
  padding: 0.85rem 1rem;
  border-radius: var(--radius-md);
  background: var(--color-surface);
}

.unassigned__title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
}

.unassigned__lead {
  margin: 0.3rem 0 0.7rem;
  font-size: 0.78rem;
  color: var(--color-text-muted);
}

.unassigned__list {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.unassigned__row {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  font-size: 0.8rem;
}

.unassigned__money {
  font-variant-numeric: tabular-nums;
  color: var(--color-text-muted);
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
