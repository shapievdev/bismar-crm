<script setup lang="ts">
import type { RaceDepartment } from '~/types/analytics'
import { formatCompactMoney, formatMoney, formatNumber, formatPercent } from '~/utils/numbers'

/**
 * Битва отделов — табло для зала.
 *
 * Тот же план и тот же факт, что на вкладке «Отделы», но повёрнутые к людям:
 * там таблица, по которой разбираются, здесь — счёт, на который смотрят.
 * Поэтому величины другие — проценты плана и места, а не рубли: в рублях
 * крепёж проиграет гипермаркету при любой работе.
 *
 * Места расставляет темп месяца, а не сегодняшний процент. День стоит три
 * процента месяца, и первое место за один удачный вторник обесценило бы и
 * гонку, и план.
 *
 * Реального времени здесь нет: витрина не хранит времени продажи и приезжает
 * ночью. Поэтому день — последний полный, и дата выгрузки стоит рядом, а не
 * прячется.
 */
definePageMeta({ middleware: 'auth', permission: 'analytics.view' })

useHead({ title: 'Битва отделов — Аналитика' })

const { fetchRace } = useAnalyticsApi()

const months = computed(() => {
  const now = new Date()

  return Array.from({ length: 24 }, (_, back) => {
    const date = new Date(now.getFullYear(), now.getMonth() - back, 1)

    return {
      value: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`,
      label: date.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }),
    }
  })
})

const month = ref(months.value[0]!.value)

const { data: response, pending, error } = await useAsyncData(
  'analytics-race',
  () => fetchRace(month.value),
  { watch: [month] },
)

const message = computed(() =>
  (error.value?.data as { message?: string } | undefined)?.message
  ?? 'Сервер аналитики недоступен. Цифры появятся, когда связь восстановится.',
)

const race = computed(() => response.value?.data)
const store = computed(() => race.value?.store)
const day = computed(() => race.value?.day)

/** Главная цифра кольца: насколько магазин идёт по графику. */
const pace = computed(() => store.value?.pace_completion ?? null)

function dateLabel(value: string | null | undefined): string {
  return value
    ? new Date(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })
    : '—'
}

function weekdayLabel(value: string | null | undefined): string {
  return value ? new Date(value).toLocaleDateString('ru-RU', { weekday: 'long' }) : ''
}

const STATUS: Record<RaceDepartment['status'], string> = {
  'ahead': 'впереди',
  'on-track': 'в графике',
  'behind': 'догоняет',
  'unknown': 'без плана',
}

/*
 * Длина полосы — выполнение темпа, но сто процентов не упираются в край:
 * обогнавшему нужно место, чтобы это было видно. Поэтому шкала тянется до
 * полутора норм, а отметка темпа стоит на двух третях.
 */
const SCALE = 150

function bar(value: number | null): number {
  return value === null ? 0 : Math.min(100, (value / SCALE) * 100)
}

const PACE_MARK = (100 / SCALE) * 100

/** Насколько день отличается от прошлого такого же — со знаком. */
function signed(value: number | null | undefined): string {
  if (value === null || value === undefined) {
    return '—'
  }

  return `${value > 0 ? '+' : ''}${formatPercent(value)}`
}

function tone(value: number | null | undefined): 'good' | 'bad' | 'muted' {
  if (value === null || value === undefined) {
    return 'muted'
  }

  return value >= 0 ? 'good' : 'bad'
}
</script>

<template>
  <div class="page">
    <header class="page__head">
      <h1 class="page__title">
        Битва отделов
      </h1>
      <p class="page__lead">
        Кто идёт по плану месяца и чем запомнился день. Места расставляет темп месяца, а не
        сегодняшний процент: день стоит около трёх процентов месяца.
      </p>
    </header>

    <div class="toolbar">
      <UiSelect v-model="month" :options="months" auto />

      <span v-if="day" class="toolbar__note">
        День: {{ dateLabel(day.date) }}, {{ weekdayLabel(day.date) }}
        <template v-if="day.loaded_to && day.loaded_to !== day.date">
          · выгрузка дошла до {{ dateLabel(day.loaded_to) }}, последний полный день — показанный
        </template>
      </span>
    </div>

    <p v-if="error" class="notice notice--error">
      {{ message }}
    </p>

    <AnalyticsBentoGrid v-else-if="race && store">
      <!-- Магазин целиком: кольцо, темп, прогноз и сравнение дня. -->
      <AnalyticsChartCard
        title="Магазин"
        :hint="`План месяца — ${store.plan_month === null ? 'не поставлен' : formatCompactMoney(store.plan_month)}`"
        :span="4"
        :rows="4"
      >
        <div class="store">
          <!-- Кольцо печатает процент само; подпись под ним объясняет, что
               это за процент, а не повторяет его другим шрифтом. -->
          <div class="store__ring">
            <UiProgressRing :value="pace ?? 0" :size="140" />
            <span class="store__caption">к темпу месяца</span>
          </div>

          <p class="store__line">
            <template v-if="store.pace_month !== null">
              К {{ race.period.days_elapsed }}-му дню ждали
              {{ formatCompactMoney(store.pace_month) }}, сделано
              {{ formatCompactMoney(store.revenue_month) }}.
            </template>
            <template v-else>
              Плана на месяц нет — сравнивать не с чем.
            </template>
          </p>

          <dl class="facts">
            <div class="fact">
              <dt>Прогноз месяца</dt>
              <dd>
                {{ store.forecast === null ? '—' : formatCompactMoney(store.forecast) }}
                <small v-if="store.forecast_completion !== null" :class="`sub sub--${store.forecast_completion >= 100 ? 'good' : 'bad'}`">
                  {{ formatPercent(store.forecast_completion) }} плана
                </small>
              </dd>
            </div>
            <div class="fact">
              <dt>День</dt>
              <dd>
                {{ formatCompactMoney(store.revenue_day) }}
                <small v-if="store.completion_day !== null" :class="`sub sub--${store.completion_day >= 100 ? 'good' : 'bad'}`">
                  {{ formatPercent(store.completion_day) }} плана дня
                </small>
              </dd>
            </div>
          </dl>

          <p class="store__against">
            К прошлому {{ weekdayLabel(day?.previous_date) }}
            <span class="sub">({{ dateLabel(day?.previous_date) }})</span>
          </p>

          <dl class="facts facts--tight">
            <div class="fact">
              <dt>Чеков</dt>
              <dd :class="`sub--${tone(store.against_previous.orders)}`">
                {{ signed(store.against_previous.orders) }}
              </dd>
            </div>
            <div class="fact">
              <dt>Средний чек</dt>
              <dd :class="`sub--${tone(store.against_previous.average_order)}`">
                {{ signed(store.against_previous.average_order) }}
              </dd>
            </div>
            <div class="fact">
              <dt>Позиций в чеке</dt>
              <dd :class="`sub--${tone(store.against_previous.lines_per_order)}`">
                {{ signed(store.against_previous.lines_per_order) }}
              </dd>
            </div>
          </dl>

          <p v-if="store.outside_month > 0" class="store__outside">
            Вне гонки {{ formatCompactMoney(store.outside_month) }} — отделы без плана и
            неразобранные продавцы.
          </p>
        </div>
      </AnalyticsChartCard>

      <!-- Гонка: полоса — выполнение темпа, пунктир — сам темп. -->
      <AnalyticsChartCard
        title="Гонка отделов"
        hint="Полоса — выполнение темпа месяца, отметка — сам темп. Процент дня рядом: он показывает сегодняшний ход, но места не меняет."
        :span="5"
        :rows="4"
        scroll
      >
        <ol class="race">
          <li
            v-for="(department, index) in race.departments"
            :key="department.id"
            class="racer"
            :class="{ 'racer--first': index === 0 }"
          >
            <span class="racer__place">{{ index + 1 }}</span>

            <div class="racer__who">
              <span class="racer__name">{{ department.name }}</span>
              <span v-if="department.head" class="sub">{{ department.head }}</span>
            </div>

            <div class="racer__track">
              <div class="track">
                <div class="track__fill" :style="{ width: `${bar(department.pace_completion)}%` }" />
                <div class="track__mark" :style="{ left: `${PACE_MARK}%` }" />
              </div>
              <span class="sub">
                день: {{ department.completion_day === null ? '—' : formatPercent(department.completion_day) }}
                плана дня
              </span>
            </div>

            <div class="racer__score">
              <strong :class="`score score--${department.status}`">
                {{ department.pace_completion === null ? '—' : formatPercent(department.pace_completion) }}
              </strong>
              <span :class="`chip chip--${department.status}`">{{ STATUS[department.status] }}</span>
            </div>
          </li>
        </ol>

        <UiEmptyState
          v-if="race.departments.length === 0"
          title="Гонки нет"
          description="Ни одному отделу не поставлен план на этот месяц."
        />
      </AnalyticsChartCard>

      <!-- Консультанты: процент личного плана, а не рубли. -->
      <AnalyticsChartCard
        title="Лучшие консультанты"
        hint="Процент личного плана. В рублях рейтинг был бы рейтингом отделов: с кассы корпоративного спрашивают миллионы, с консультанта крепежа — сотни тысяч."
        :span="3"
        :rows="4"
        scroll
      >
        <ol class="people">
          <li v-for="(person, index) in race.consultants" :key="person.external_name" class="person">
            <span class="person__place" :class="{ 'person__place--top': index < 3 }">{{ index + 1 }}</span>
            <div class="person__who">
              <span class="person__name">{{ person.name }}</span>
              <span v-if="person.department" class="sub">{{ person.department }}</span>
            </div>
            <strong class="person__score">{{ formatPercent(person.completion) }}</strong>
          </li>
        </ol>

        <UiEmptyState
          v-if="race.consultants.length === 0"
          title="Пусто"
          description="Ни у кого нет плана на этот месяц."
        />
      </AnalyticsChartCard>

      <!-- Рекорды: четыре разные величины, иначе лучшим всегда был бы один. -->
      <AnalyticsChartCard
        title="Рекорды дня"
        :hint="`${dateLabel(day?.date)} — последний полный день выгрузки`"
        :span="12"
        :rows="1"
      >
        <div class="records">
          <div class="record">
            <span class="record__label">Лидер дня</span>
            <strong class="record__value">
              {{ race.records.leader ? formatPercent(race.records.leader.share) : '—' }}
            </strong>
            <span class="record__who">
              {{ race.records.leader?.name ?? 'нет данных' }}
              <small v-if="race.records.leader?.department" class="sub">{{ race.records.leader.department }}</small>
            </span>
          </div>

          <div class="record">
            <span class="record__label">Рывок дня</span>
            <strong class="record__value">{{ signed(race.records.surge?.change) }}</strong>
            <span class="record__who">
              {{ race.records.surge?.name ?? 'нет данных' }}
              <small class="sub">к прошлому {{ weekdayLabel(day?.previous_date) }}</small>
            </span>
          </div>

          <div class="record">
            <span class="record__label">Лучший средний чек</span>
            <strong class="record__value">{{ signed(race.records.average_order?.change) }}</strong>
            <span class="record__who">
              {{ race.records.average_order?.name ?? 'нет данных' }}
              <small v-if="race.records.average_order" class="sub">
                {{ formatMoney(race.records.average_order.value) }} · к магазину
              </small>
            </span>
          </div>

          <div class="record">
            <span class="record__label">Больше позиций в чеке</span>
            <strong class="record__value">{{ signed(race.records.lines_per_order?.change) }}</strong>
            <span class="record__who">
              {{ race.records.lines_per_order?.name ?? 'нет данных' }}
              <small v-if="race.records.lines_per_order" class="sub">
                {{ formatNumber(race.records.lines_per_order.value, 2) }} позиции · к магазину
              </small>
            </span>
          </div>
        </div>
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
  margin-bottom: 1rem;
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
  margin-bottom: 0.85rem;
}

.toolbar__note {
  font-size: 0.78rem;
  color: var(--color-text-faint);
}

.sub {
  font-size: 0.72rem;
  color: var(--color-text-faint);
}

.sub--good {
  color: var(--color-success);
}

.sub--bad {
  color: var(--color-danger);
}

.sub--muted {
  color: var(--color-text-faint);
}

/* ---------- Магазин ---------- */

.store {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
}

.store__ring {
  align-self: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.3rem;
}

.store__caption {
  font-size: 0.7rem;
  color: var(--color-text-faint);
}

.store__line {
  margin: 0;
  font-size: 0.78rem;
  color: var(--color-text-muted);
  text-align: center;
}

.facts {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin: 0;
  padding-top: 0.6rem;
  border-top: 1px solid var(--color-border);
}

.facts--tight {
  padding-top: 0.35rem;
  border-top: 0;
}

.fact {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
}

.fact dt {
  font-size: 0.78rem;
  color: var(--color-text-muted);
}

.fact dd {
  display: flex;
  align-items: baseline;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.85rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.store__against {
  margin: 0.3rem 0 0;
  padding-top: 0.6rem;
  border-top: 1px solid var(--color-border);
  font-size: 0.78rem;
  color: var(--color-text-muted);
}

.store__outside {
  margin: 0;
  font-size: 0.7rem;
  color: var(--color-text-faint);
}

/* ---------- Гонка ---------- */

.race,
.people {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  margin: 0;
  padding: 0;
  list-style: none;
  counter-reset: place;
}

.racer {
  display: grid;
  grid-template-columns: 1.5rem minmax(0, 1fr) minmax(0, 1.3fr) auto;
  align-items: center;
  gap: 0.6rem;
  padding: 0.45rem 0.5rem;
  border-radius: var(--radius-sm);
}

/* Первое место отмечено фактурой, а не цветом: цветов на табло и так много,
   и лишний означал бы ещё одну величину, которой нет. */
.racer--first {
  background: var(--color-surface-sunken);
}

.racer__place {
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--color-text-faint);
  font-variant-numeric: tabular-nums;
}

.racer--first .racer__place {
  color: var(--color-text);
}

.racer__who {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}

.racer__name {
  font-size: 0.84rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.racer__track {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  min-width: 0;
}

.track {
  position: relative;
  height: 0.5rem;
  border-radius: 999px;
  background: var(--color-surface-sunken);
  overflow: hidden;
}

.track__fill {
  height: 100%;
  border-radius: 999px;
  background: var(--color-text);
  transition: width 0.25s ease;
}

/* Отметка темпа: где полоса должна стоять, если идти ровно. */
.track__mark {
  position: absolute;
  top: -2px;
  bottom: -2px;
  width: 2px;
  background: var(--color-danger);
}

.racer__score {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.15rem;
}

.score {
  font-size: 1.05rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.score--ahead {
  color: var(--color-success);
}

.score--behind {
  color: var(--color-danger);
}

.chip {
  padding: 0.05rem 0.4rem;
  border-radius: 999px;
  font-size: 0.65rem;
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
  white-space: nowrap;
}

.chip--ahead {
  background: var(--color-success-soft, var(--color-surface-sunken));
  color: var(--color-success);
}

.chip--behind {
  background: var(--color-danger-soft);
  color: var(--color-danger);
}

/* ---------- Консультанты ---------- */

.person {
  display: grid;
  grid-template-columns: 1.3rem minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.25rem;
}

.person__place {
  font-size: 0.78rem;
  color: var(--color-text-faint);
  font-variant-numeric: tabular-nums;
}

.person__place--top {
  color: var(--color-text);
  font-weight: 600;
}

.person__who {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
  min-width: 0;
}

.person__name {
  font-size: 0.8rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.person__score {
  font-size: 0.9rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

/* ---------- Рекорды ---------- */

.records {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
  gap: 0.75rem;
}

.record {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding-left: 0.75rem;
  border-left: 2px solid var(--color-border);
}

.record__label {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-faint);
}

.record__value {
  font-size: 1.4rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.02em;
}

.record__who {
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
  font-size: 0.8rem;
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
