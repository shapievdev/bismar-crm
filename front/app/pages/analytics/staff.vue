<script setup lang="ts">
import type { StaffPerson, StaffQuery } from '~/types/analytics'
import { formatNumber } from '~/utils/numbers'

/**
 * Аналитика штата: движение персонала.
 *
 * Отдельно от обучения, хотя вкладки соседние: там спрашивают «как проходят
 * курсы», здесь — «что происходит с людьми». Общее у них только то, что оба
 * считают по своей базе, а не по витрине продаж.
 *
 * Период здесь, наоборот, обязателен: текучесть без периода — бессмыслица, а
 * «принято» копится не с начала времён, а за месяц, квартал или год.
 *
 * Из любой цифры проваливаются в список людей. Список — персональные данные, и
 * приезжает он отдельным запросом, а не вместе со сводкой: открывший отчёт ради
 * процента текучести не должен получать заодно и поимённый перечень.
 */
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Штат — Аналитика' })

const { fetchStaff, fetchStaffPeople } = useAnalyticsApi()

/* ---------- Срез ---------- */

/**
 * Срез живёт в адресе.
 *
 * Не ради красоты: отчётом делятся — «посмотри розницу за август» отправляют
 * ссылкой, а не пересказом того, какие галочки нажать. Заодно работает кнопка
 * «назад»: вернувшись из карточки сотрудника, человек попадает в тот же срез, а
 * не в текущий месяц.
 */
const route = useRoute()
const router = useRouter()

function queryFromRoute(): StaffQuery {
  const one = (value: unknown): string | undefined => {
    const text = Array.isArray(value) ? value[0] : value

    return typeof text === 'string' && text !== '' ? text : undefined
  }

  const numbers = (value: unknown): number[] => (Array.isArray(value) ? value : [value])
    .map(Number)
    .filter(Number.isFinite)

  return {
    from: one(route.query.from),
    to: one(route.query.to),
    job_title: one(route.query.job_title),
    work_mode: one(route.query.work_mode),
    departments: route.query.departments === undefined ? [] : numbers(route.query.departments),
    tags: route.query.tags === undefined ? [] : numbers(route.query.tags),
  }
}

const query = ref<StaffQuery>(queryFromRoute())

/**
 * Правка среза меняет адрес, а адрес — срез.
 *
 * `replace`, а не `push`: подбор периода — это уточнение одного и того же
 * вопроса, и десять нажатий по фильтрам не должны требовать десяти нажатий
 * «назад», чтобы выйти со страницы.
 */
watch(query, (value) => {
  void router.replace({
    query: Object.fromEntries(
      Object.entries(value).filter(([, one]) => one !== undefined
        && one !== ''
        && !(Array.isArray(one) && one.length === 0)),
    ),
  })
})

watch(() => route.query, () => {
  const fromUrl = queryFromRoute()

  if (JSON.stringify(fromUrl) !== JSON.stringify(query.value)) {
    query.value = fromUrl
  }
})

const { data, pending, error, refresh } = await useAsyncData(
  'analytics-staff',
  async () => (await fetchStaff(query.value)).data,
  { watch: [query] },
)

const summary = computed(() => data.value?.summary ?? null)
const options = computed(() => data.value?.filters ?? null)

/**
 * Готовые периоды — то, чем пользуются в девяти случаях из десяти.
 *
 * Произвольные даты остаются рядом: «с 3 по 17 марта» спрашивают редко, но
 * когда спрашивают, без них не обойтись.
 */
type PeriodKind = 'month' | 'quarter' | 'year'

const PERIODS: { kind: PeriodKind, label: string }[] = [
  { kind: 'month', label: 'Месяц' },
  { kind: 'quarter', label: 'Квартал' },
  { kind: 'year', label: 'Год' },
]

/**
 * Границы готового периода — календарные, целиком.
 *
 * «Месяц» — это сентябрь, а не «сентябрь по сегодня»: кадровик спрашивает про
 * месяц, а не про его начало. Будущие дни отчёту не мешают — в них никого не
 * принимают и не увольняют, — зато так границы совпадают с тем, что считает
 * сервер по умолчанию, и открытая без ссылки страница сразу показывает, какая
 * кнопка нажата.
 */
function periodOf(kind: PeriodKind): { from: string, to: string } {
  const today = new Date()
  const year = today.getFullYear()

  const [first, afterLast] = kind === 'month'
    ? [today.getMonth(), today.getMonth() + 1]
    : kind === 'quarter'
      ? [Math.floor(today.getMonth() / 3) * 3, Math.floor(today.getMonth() / 3) * 3 + 3]
      : [0, 12]

  // Нулевой день следующего месяца — последний день предыдущего: так не нужно
  // помнить ни про тридцать дней, ни про високосный февраль.
  return { from: iso(new Date(year, first, 1)), to: iso(new Date(year, afterLast, 0)) }
}

function setPeriod(kind: PeriodKind): void {
  query.value = { ...query.value, ...periodOf(kind) }
}

/**
 * Какая из готовых кнопок сейчас нажата — если нажата вообще.
 *
 * Считается сравнением, а не запоминается отдельно: срез живёт в адресе, и
 * человек, пришедший по чужой ссылке, должен видеть подсвеченным то же, что
 * увидел бы, нажав кнопку сам. Произвольные даты не подсвечивают ничего — и
 * это честно: «с 3 по 17 марта» не месяц и не квартал.
 */
const activePeriod = computed<PeriodKind | null>(() => {
  // По тому, что показано, а не по тому, что лежит в адресе: открытая без
  // ссылки страница берёт период у сервера, и кнопка обязана подсветиться и
  // там — иначе панель показывает сентябрь, не признавая, что это месяц.
  const from = query.value.from ?? options.value?.period.from
  const to = query.value.to ?? options.value?.period.to

  return PERIODS.find((one) => {
    const range = periodOf(one.kind)

    return range.from === from && range.to === to
  })?.kind ?? null
})

/**
 * Дата местная, а не всемирная.
 *
 * `toISOString()` переводит в UTC, и в Москве до трёх ночи «сегодня» съезжало
 * бы на вчера: нажатие «Месяц» первого числа давало бы период, начинающийся в
 * прошлом месяце.
 */
function iso(date: Date): string {
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
  ].join('-')
}

/* ---------- Люди за цифрой ---------- */

const slice = ref<string | null>(null)
const people = ref<StaffPerson[]>([])
const isLoadingPeople = ref(false)

const SLICE_TITLES: Record<string, string> = {
  headcount: 'Числятся на конец периода',
  'hired': 'Приняты за период',
  'left': 'Уволены за период',
  'early-left': 'Ушли в первые 90 дней',
  'without-hire-date': 'Без даты приёма',
}

async function open(which: string): Promise<void> {
  if (slice.value === which) {
    slice.value = null
    people.value = []

    return
  }

  slice.value = which
  isLoadingPeople.value = true

  try {
    people.value = (await fetchStaffPeople(query.value, which)).data.people
  }
  finally {
    isLoadingPeople.value = false
  }
}

// Сменили срез — открытый список уже не о том.
watch(query, () => {
  slice.value = null
  people.value = []
})

/* ---------- Показ ---------- */

/** Месяцы словами: «1 год 4 месяца» читается, «16» — нет. */
function tenure(months: number | null): string {
  if (months === null) {
    return '—'
  }

  const years = Math.floor(months / 12)
  const rest = months % 12

  return [
    years > 0 ? `${years} ${pluralise(years, 'год', 'года', 'лет')}` : null,
    rest > 0 || years === 0 ? `${rest} ${pluralise(rest, 'месяц', 'месяца', 'месяцев')}` : null,
  ].filter(Boolean).join(' ')
}

function when(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU') : '—'
}

/** Насколько численность изменилась за период — одним знаком. */
const drift = computed(() => {
  const numbers = summary.value

  return numbers ? numbers.headcount_end - numbers.headcount_start : 0
})
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Штат
        </h1>
        <p class="page-subtitle">
          Движение персонала за период: кого приняли, кто ушёл и сколько продержались.
          Из любой цифры можно провалиться в список людей.
        </p>
      </div>

      <AnalyticsStaffShare :query="query" :people="summary?.headcount_end ?? 0" />
    </header>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось посчитать движение персонала.
    </p>

    <template v-else>
      <!-- Срез стоит над отчётом, а не сбоку: его меняют чаще, чем читают
           отдельную цифру, и он относится ко всему, что ниже. -->
      <div v-if="options" class="filters card">
        <!-- Готовые периоды — такое же поле среза, как и остальные, и подпись у
             них своя: без неё ряд начинался бы тремя кнопками, висящими ниже
             всего прочего, и верхний край панели шёл бы уступом. -->
        <div class="filters__field filters__field--period">
          <span class="field-label">Период</span>
          <div class="segment" role="group" aria-label="Готовые периоды">
            <button
              v-for="one in PERIODS"
              :key="one.kind"
              type="button"
              class="segment__item"
              :class="{ 'segment__item--active': activePeriod === one.kind }"
              :aria-pressed="activePeriod === one.kind"
              @click="setPeriod(one.kind)"
            >
              {{ one.label }}
            </button>
          </div>
        </div>

        <label class="filters__field filters__field--date">
          <span class="field-label">С</span>
          <input
            :value="query.from ?? options.period.from"
            type="date"
            class="input"
            @change="query = { ...query, from: ($event.target as HTMLInputElement).value }"
          >
        </label>

        <label class="filters__field filters__field--date">
          <span class="field-label">По</span>
          <input
            :value="query.to ?? options.period.to"
            type="date"
            class="input"
            @change="query = { ...query, to: ($event.target as HTMLInputElement).value }"
          >
        </label>

        <label v-if="options.departments.length > 1" class="filters__field">
          <span class="field-label">Подразделение</span>
          <UiSelect
            :model-value="String(query.departments?.[0] ?? '')"
            :options="[
              { value: '', label: 'Все' },
              ...options.departments.map(one => ({ value: String(one.id), label: one.name })),
            ]"
            searchable
            @update:model-value="query = { ...query, departments: $event ? [Number($event)] : [] }"
          />
        </label>

        <label v-if="options.job_titles.length" class="filters__field">
          <span class="field-label">Должность</span>
          <UiSelect
            :model-value="query.job_title ?? ''"
            :options="[
              { value: '', label: 'Все' },
              ...options.job_titles.map(one => ({ value: one, label: one })),
            ]"
            searchable
            @update:model-value="query = { ...query, job_title: $event || undefined }"
          />
        </label>

        <label class="filters__field">
          <span class="field-label">Режим</span>
          <UiSelect
            :model-value="query.work_mode ?? ''"
            :options="[{ value: '', label: 'Любой' }, ...options.work_modes]"
            @update:model-value="query = { ...query, work_mode: $event || undefined }"
          />
        </label>

        <label v-if="options.tags.length" class="filters__field">
          <span class="field-label">Тег</span>
          <UiSelect
            :model-value="String(query.tags?.[0] ?? '')"
            :options="[
              { value: '', label: 'Любой' },
              ...options.tags.map(one => ({ value: String(one.id), label: one.name })),
            ]"
            @update:model-value="query = { ...query, tags: $event ? [Number($event)] : [] }"
          />
        </label>

        <!-- Директору направления незачем гадать, почему он видит меньше
             коллеги: об этом сказано прямо. -->
        <p v-if="!options.sees_everything" class="filters__note muted">
          Показаны только ваши подразделения.
        </p>
      </div>

      <div v-if="pending" class="skeleton skeleton-block" />

      <AnalyticsBentoGrid v-else-if="summary">
        <AnalyticsStatTile
          label="Численность на конец"
          :value="summary.headcount_end"
          :span="3"
          :hint="`На начало ${formatNumber(summary.headcount_start)} · ${drift >= 0 ? '+' : '−'}${Math.abs(drift)} за период`"
        />
        <AnalyticsStatTile
          label="Принято"
          :value="summary.hired"
          :span="3"
          hint="Нажмите, чтобы увидеть кого"
        />
        <AnalyticsStatTile
          label="Уволено"
          :value="summary.left"
          :span="3"
          :hint="summary.average_life === null
            ? 'За период никто не уходил'
            : `В среднем продержались ${tenure(summary.average_life)}`"
        />
        <AnalyticsStatTile
          label="Текучесть"
          :value="summary.turnover"
          format="percent"
          :span="3"
          :attention="summary.turnover >= 30"
          :hint="`От среднесписочной ${summary.average_headcount}`"
        />

        <AnalyticsStatTile
          label="Ранняя текучесть"
          :value="summary.early_turnover"
          format="percent"
          :span="3"
          :attention="summary.early_turnover >= 20"
          :hint="`${formatNumber(summary.early_left)} ушли в первые 90 дней от ${formatNumber(summary.hired)} принятых`"
        />
        <AnalyticsStatTile
          label="Средний стаж"
          :value="summary.average_tenure ?? 0"
          :span="3"
          :unknown="summary.average_tenure === null"
          :hint="summary.average_tenure === null
            ? 'Ни у кого не заполнена дата приёма'
            : tenure(summary.average_tenure)"
        />
        <AnalyticsStatTile
          label="Онбординг"
          :value="summary.onboarding ?? 0"
          format="percent"
          :span="3"
          :unknown="summary.onboarding === null"
          :hint="summary.onboarding === null
            ? 'Аттестация испытательного срока не заведена'
            : 'Новички, сдавшие аттестацию 30-го дня'"
        />
        <AnalyticsStatTile
          label="Без даты приёма"
          :value="summary.without_hire_date"
          :span="3"
          :attention="summary.without_hire_date > 0"
          hint="Эти люди не попадают ни в одну цифру выше"
        />

        <!-- Провал в список: кнопками под плитками, а не самими плитками.
             Плитка — цифра, и нажимать на число, чтобы что-то произошло, никто
             не догадается. -->
        <AnalyticsChartCard title="Кто за цифрами" :span="12" :rows="1">
          <div class="slices">
            <button
              v-for="(title, key) in SLICE_TITLES"
              :key="key"
              type="button"
              class="button-secondary button-sm"
              :aria-pressed="slice === key"
              @click="open(String(key))"
            >
              {{ title }}
            </button>
          </div>

          <p v-if="isLoadingPeople" class="muted">
            Загружаем…
          </p>

          <template v-else-if="slice">
            <p v-if="!people.length" class="muted">
              Никого: за этот срез таких людей нет.
            </p>

            <table v-else class="people data-table">
              <thead>
                <tr>
                  <th>Сотрудник</th>
                  <th>Подразделение</th>
                  <th>Принят</th>
                  <th>Стаж</th>
                  <th>Положение</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="person in people" :key="person.id">
                  <td>
                    <NuxtLink :to="`/staff/${person.id}`" class="people__name">
                      {{ person.name }}
                    </NuxtLink>
                    <span v-if="person.job_title" class="muted people__title">{{ person.job_title }}</span>
                  </td>
                  <td class="muted" data-label="Подразделение">
                    {{ person.departments.join(', ') || '—' }}
                  </td>
                  <td class="data-table__number" data-label="Принят">
                    {{ when(person.hired_at) }}
                    <span v-if="person.dismissed_at" class="muted">→ {{ when(person.dismissed_at) }}</span>
                  </td>
                  <td class="data-table__number wraps" data-label="Стаж">
                    {{ tenure(person.tenure_months) }}
                    <span v-if="person.tenure_tag_label" class="badge">{{ person.tenure_tag_label }}</span>
                  </td>
                  <td class="wraps">
                    <span
                      class="badge"
                      :class="person.status === 'dismissed' ? 'badge--warning' : 'badge--success'"
                    >{{ person.status_label }}</span>
                    <span v-if="person.dismissal_reason_label" class="muted">
                      {{ person.dismissal_reason_label }}
                    </span>
                    <span v-for="tag in person.tags" :key="tag" class="badge badge--accent">{{ tag }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </template>
        </AnalyticsChartCard>

        <AnalyticsChartCard
          title="Принято и уволено по месяцам"
          hint="Вверх — приём, вниз — уход. Месяц, где нижняя половина длиннее, — месяц, когда компания уменьшилась"
          :span="12"
          :rows="2"
        >
          <AnalyticsMovementChart v-if="data?.movement.length" :months="data.movement" />
          <UiEmptyState v-else title="Период пуст" description="Выберите другой срез." />
        </AnalyticsChartCard>

        <AnalyticsChartCard
          title="По подразделениям"
          hint="Человек в двух отделах посчитан в каждом: отчёт о подразделении, а не о долях человека"
          :span="7"
          :rows="3"
        >
          <table v-if="data?.departments.length" class="departments data-table">
            <thead>
              <tr>
                <th>Подразделение</th>
                <th>Числятся</th>
                <th>Принято</th>
                <th>Уволено</th>
                <th>Текучесть</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in data.departments" :key="row.id">
                <td>{{ row.name }}</td>
                <td class="data-table__number" data-label="Числятся">
                  {{ row.headcount }}
                </td>
                <td class="data-table__number" data-label="Принято">
                  {{ row.hired }}
                </td>
                <td class="data-table__number" data-label="Уволено">
                  {{ row.left }}
                </td>
                <td class="data-table__number" data-label="Текучесть">
                  <span :class="{ 'departments__hot': row.turnover >= 30 }">{{ row.turnover }}%</span>
                </td>
              </tr>
            </tbody>
          </table>
          <UiEmptyState v-else title="Пусто" description="За этот срез движения не было." />
        </AnalyticsChartCard>

        <AnalyticsChartCard
          title="Распределение по стажу"
          hint="Работающие на конец периода. Стаж считается сам из даты приёма и не хранится"
          :span="5"
          :rows="3"
        >
          <AnalyticsBarList
            v-if="data?.tenure.length"
            :rows="data.tenure.map(row => ({ name: row.label, value: row.count, meta: row.range }))"
            format="number"
          />
        </AnalyticsChartCard>

        <AnalyticsChartCard
          title="Причины ухода"
          :hint="data?.reasons.unknown
            ? `У ${data.reasons.unknown} ушедших причина не проставлена — в доли они не входят`
            : 'Доли считаются от тех, у кого причина проставлена'"
          :span="12"
          :rows="2"
        >
          <AnalyticsBarList
            v-if="data?.reasons.rows.length"
            :rows="data.reasons.rows.map(row => ({
              name: row.label,
              value: row.count,
              total: data!.reasons.total,
              meta: `${row.share}% ушедших`,
            }))"
            format="number"
          />
          <UiEmptyState v-else title="За период никто не уходил" description="И это лучший вид этой панели." />
        </AnalyticsChartCard>
      </AnalyticsBentoGrid>
    </template>
  </section>
</template>

<style scoped>
.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.25rem;
}

/* Телефон: кнопка уходит под заголовок. Рядом с ним она отняла бы у названия
   половину ширины, и «Штат» переносилось бы по букве. */
@media (max-width: 40rem) {
  .head {
    flex-direction: column;
    gap: 0.75rem;
  }
}

.muted {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

/*
 * Срез — один ряд равноправных полей.
 *
 * Каждое из них устроено одинаково: подпись сверху, управление под ней. Оттого
 * и подписи стоят на одной строке, и верхний край ряда не идёт уступом, как
 * шёл бы, окажись среди них хоть одно поле без подписи.
 */
.filters {
  /*
   * Сеткой из четырёх колонок, а не рядом с переносом.
   *
   * Полей семь, и в одну строку они не помещаются ни на каком разумном экране —
   * перенос будет всегда. У ряда с переносом он случаен: последнее поле
   * растягивается на всю освободившуюся строку, и «Тег» шириной в панель
   * выглядит ошибкой вёрстки, а не фильтром.
   *
   * Две строки по четыре — перенос, выбранный нарочно, и заодно смысловое
   * деление: сверху «за какой срок», снизу «по кому».
   */
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  align-items: start;
  gap: 0.8rem 0.9rem;
  padding: 1rem 1.15rem;
  margin-bottom: 1rem;
}

.filters__field {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 0.3rem;
}

/* Переключателю нужно место под три слова сразу — он занимает две колонки. */
.filters__field--period {
  grid-column: span 2;
}

/*
 * Переключатель периода: три части одного целого, а не три отдельные кнопки.
 * Общая подложка говорит, что выбор здесь один из трёх, — и заодно ровняет
 * высоту с соседними полями ввода.
 */
.segment {
  display: flex;
  /* По содержимому, а не во всю колонку: растянутая подложка оставляла бы
     после «Года» серый хвост, в котором нечего нажимать. */
  align-self: flex-start;
  padding: 0.2rem;
  border-radius: var(--radius-pill);
  background: var(--color-surface-sunken);
  gap: 0.15rem;
}

.segment__item {
  padding: 0.45rem 0.85rem;
  border: 0;
  border-radius: var(--radius-pill);
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.9rem;
  line-height: 1.2;
  white-space: nowrap;
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease;
}

.segment__item:hover:not(.segment__item--active) {
  color: var(--color-text);
}

.segment__item--active {
  background: var(--color-surface-raised);
  color: var(--color-text);
  font-weight: 500;
  box-shadow: var(--shadow-sm);
}

.filters__note {
  grid-column: 1 / -1;
  margin: 0;
}

@media (prefers-reduced-motion: reduce) {
  .segment__item {
    transition: none;
  }
}

/* Телефон: поля в два столбца, переключатель периода — во всю ширину над ними.
   В одну колонку срез занимал бы экран целиком, и до первой цифры пришлось бы
   листать. */
@media (max-width: 40rem) {
  .filters {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    padding: 0.85rem 0.9rem;
  }

  .filters__field--period {
    grid-column: 1 / -1;
  }

  .segment {
    justify-content: space-between;
  }

  /* Три равные доли на всю ширину — и повыше: в палец надо попадать, а не
     целиться. */
  .segment__item {
    flex: 1;
    padding-block: 0.6rem;
  }

  /* То же и у кнопок, которыми проваливаются в список людей. */
  .slices .button-sm {
    padding-block: 0.55rem;
  }

  /*
   * Поля ужимаются по бокам.
   *
   * Отступ в рамку, рассчитанный на просторную форму, в половине телефонного
   * экрана съедает треть поля: «01.01.2026» обрезается до «01.01.20», а «Любой»
   * до «Люб…». Значение важнее воздуха вокруг него.
   */
  .filters .input,
  .filters :deep(.select-field__control) {
    padding-inline: 0.7rem;
  }

  /*
   * Дате отступы режутся сильнее прочих: у поля `date` есть собственный значок
   * календаря, который отъедает ширину сверх рамки, и «01.01.2026» иначе
   * теряет последнюю цифру.
   *
   * Уменьшать кегль тут бесполезно: общее правило для сенсорных экранов держит
   * у полей ввода 16 пикселей насильно, иначе iOS приближает страницу при
   * касании. Место приходится искать в отступах.
   */
  .filters .input[type='date'] {
    padding-inline: 0.55rem;
  }
}

.slices {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-bottom: 0.75rem;
}

.slices [aria-pressed='true'] {
  background: var(--color-accent);
  color: var(--color-accent-text);
}

.people__name {
  color: inherit;
  font-weight: 500;
  text-decoration: none;
}

.people__name:hover {
  text-decoration: underline;
}

.people__title {
  display: block;
}

/*
 * Ячейка, которой перенос разрешён.
 *
 * Общий запрет переноса для числовых ячеек бережёт даты — «15.06.2026 →
 * 02.07.2026» посреди разрыва читается как две разные, — но рядом со стажем и
 * положением стоят бейджи, а их бывает до четырёх. Без переноса они распирают
 * таблицу до горизонтальной прокрутки, и цифры уезжают за край.
 */
.wraps {
  white-space: normal;
}

/* Высокая текучесть — не «плохо», а «сюда стоит посмотреть». */
.departments__hot {
  color: var(--color-warning);
  font-weight: 600;
}

.skeleton-block {
  width: 100%;
  height: 18rem;
}
</style>
