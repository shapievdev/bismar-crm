<script setup lang="ts">
import type { AnalyticsFilters, Directory, FilterList, Granularity, PeriodPreset } from '~/types/analytics'
import { formatCompactMoney, formatDate } from '~/utils/numbers'

const props = withDefaults(defineProps<{
  /** Ещё не загружен — строка рисуется пустой, а не подскакивает позже. */
  directory?: Directory | null
  /**
   * Какие отборы показывать.
   *
   * Все четыре относятся к одной витрине и работают на любой вкладке, поэтому
   * по умолчанию показываются все. Параметр оставлен на случай панели, где
   * часть из них ничего не изменит: молча игнорировать выбор хуже, чем не
   * предлагать рычаг вовсе.
   */
  controls?: FilterList[]
}>(), {
  controls: () => ['channels', 'warehouses', 'managers', 'segments'],
})

const filters = defineModel<AnalyticsFilters>({ required: true })

/*
 * Фильтры стоят одной строкой над панелями и относятся ко всему, что ниже.
 * Разложенные по карточкам, они читались бы как настройка одной панели.
 */

const presets = computed(() => props.directory?.periods ?? [])

function selectPreset(preset: string) {
  filters.value = {
    ...filters.value,
    preset: preset as PeriodPreset,
    // Пресет и явные даты — взаимоисключающие ответы на один вопрос.
    from: undefined,
    to: undefined,
  }
}

/*
 * Произвольный период. Пресеты закрывают восемь случаев из десяти, но
 * «с первого по пятнадцатое» ими не выразить, а именно так сверяют отчёт с
 * бумагой. Даты и пресет — взаимоисключающие ответы на один вопрос, поэтому
 * выбор одного гасит другой.
 */
const isCustomOpen = ref(false)

const customFrom = ref(filters.value.from ?? '')
const customTo = ref(filters.value.to ?? '')

/*
 * Поля дат следуют за срезом, а не помнят своё.
 *
 * Срез общий на весь раздел: его меняют и соседняя вкладка, и кнопка сброса. Не
 * перечитывая его, поля показывали бы отменённый период — и «Показать» вернуло
 * бы человека туда, откуда он только что ушёл.
 */
watch(() => [filters.value.from, filters.value.to], ([from, to]) => {
  customFrom.value = from ?? ''
  customTo.value = to ?? ''
})

/** Витрина заканчивается сегодняшним днём — дальше выбирать нечего. */
const today = new Date().toISOString().slice(0, 10)

const isCustom = computed(() => Boolean(filters.value.from && filters.value.to))

const customLabel = computed(() =>
  isCustom.value ? `${formatDate(filters.value.from!)} — ${formatDate(filters.value.to!)}` : 'Свой период',
)

function applyCustom() {
  if (!customFrom.value || !customTo.value) {
    return
  }

  // Даты, выбранные наоборот, разворачиваются: это опечатка, а не повод для
  // отказа.
  const [from, to] = customFrom.value <= customTo.value
    ? [customFrom.value, customTo.value]
    : [customTo.value, customFrom.value]

  filters.value = { ...filters.value, from, to, preset: undefined }
  isCustomOpen.value = false
}

function clearCustom() {
  customFrom.value = ''
  customTo.value = ''
  filters.value = { ...filters.value, from: undefined, to: undefined, preset: 'month' }
  isCustomOpen.value = false
}

const LIST_LABELS: Record<FilterList, string> = {
  channels: 'Каналы',
  warehouses: 'Склады',
  managers: 'Менеджеры',
  segments: 'Сегменты',
}

const lists = computed<FilterList[]>(() =>
  (['channels', 'warehouses', 'managers', 'segments'] as const).filter(kind => props.controls.includes(kind)),
)

/** Открытый список: менеджеров 115, сегментов 33 — в строку они не помещаются. */
const openList = ref<FilterList | null>(null)

function toggleValue(kind: FilterList, value: string) {
  const current = filters.value[kind]

  filters.value = {
    ...filters.value,
    [kind]: current.includes(value)
      ? current.filter(item => item !== value)
      : [...current, value],
  }
}

function clear(kind: FilterList) {
  filters.value = { ...filters.value, [kind]: [] }
}

function summary(kind: FilterList): string {
  const selected = filters.value[kind]
  const label = LIST_LABELS[kind]

  if (selected.length === 0) {
    return label
  }

  return selected.length === 1 ? selected[0]! : `${label}: ${selected.length}`
}

function toggleReturns() {
  filters.value = { ...filters.value, withReturns: !filters.value.withReturns }
}

/*
 * Шаг графика. По умолчанию его выбирает сервер по длине отрезка — день для
 * квартала, неделя для года, — и в девяти случаях из десяти этого достаточно.
 * Рычаг нужен десятому: месяц по неделям сравнивают с соседним месяцем, а
 * годовой срез по месяцам — с прошлым годом, и оба раза автоматика даёт не тот
 * шаг. Пустое значение означает «как сочтёт сервер», а не «день».
 */
const GRANULARITY_LABELS: Record<Granularity, string> = {
  day: 'Дни',
  week: 'Недели',
  month: 'Месяцы',
}

const granularities = computed(() => props.directory?.granularities ?? [])

function selectGranularity(granularity?: Granularity) {
  filters.value = { ...filters.value, granularity }
}

/*
 * Сброс касается только того, что вкладка показывает. Иначе склад, выбранный
 * на продажах, предлагал бы сбросить себя там, где его нет, — и человек не
 * понял бы, что именно он сбрасывает. Период в это «показываемое» входит:
 * произвольный отрезок и шаг задаются в той же строке и точно так же
 * переживают переход на соседнюю вкладку.
 */
const hasSelection = computed(() =>
  lists.value.some(kind => filters.value[kind].length > 0)
  || !filters.value.withReturns
  || isCustom.value
  || Boolean(filters.value.granularity),
)

function reset() {
  const cleared = {
    ...filters.value,
    withReturns: true,
    preset: 'month' as PeriodPreset,
    from: undefined,
    to: undefined,
    granularity: undefined,
  }

  lists.value.forEach((kind) => {
    cleared[kind] = []
  })

  filters.value = cleared
  isCustomOpen.value = false
  openList.value = null
}

/** Закрытие списка по клику мимо него. */
const root = ref<HTMLElement | null>(null)

function handleOutside(event: MouseEvent) {
  if (root.value && !root.value.contains(event.target as Node)) {
    openList.value = null
    isCustomOpen.value = false
  }
}

onMounted(() => document.addEventListener('click', handleOutside))
onBeforeUnmount(() => document.removeEventListener('click', handleOutside))
</script>

<template>
  <div ref="root" class="filters">
    <div class="filters__group" role="group" aria-label="Период">
      <button
        v-for="preset in presets"
        :key="preset.value"
        type="button"
        class="pill"
        :class="{ 'pill--active': filters.preset === preset.value && !isCustom }"
        :aria-pressed="filters.preset === preset.value && !isCustom"
        @click="selectPreset(preset.value)"
      >
        {{ preset.label }}
      </button>
    </div>

    <div class="dropdown">
      <button
        type="button"
        class="pill pill--quiet"
        :class="{ 'pill--active': isCustom }"
        :aria-expanded="isCustomOpen"
        @click="isCustomOpen = !isCustomOpen"
      >
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
          <rect x="3.5" y="5" width="17" height="15.5" rx="2.5" />
          <path d="M3.5 9.5h17M8 3.5v3M16 3.5v3" />
        </svg>
        {{ customLabel }}
      </button>

      <div v-if="isCustomOpen" class="dropdown__panel dropdown__panel--dates">
        <label class="date">
          <span class="date__label">С</span>
          <input v-model="customFrom" type="date" :max="today" class="date__input">
        </label>

        <label class="date">
          <span class="date__label">По</span>
          <input v-model="customTo" type="date" :max="today" class="date__input">
        </label>

        <div class="date__actions">
          <button
            type="button"
            class="date__apply"
            :disabled="!customFrom || !customTo"
            @click="applyCustom"
          >
            Показать
          </button>

          <button v-if="isCustom" type="button" class="dropdown__clear" @click="clearCustom">
            Вернуть месяц
          </button>
        </div>
      </div>
    </div>

    <!-- Шаг графика. «Авто» — не отсутствие выбора, а сам выбор: пусть решает
         длина отрезка. -->
    <div v-if="granularities.length" class="filters__group" role="group" aria-label="Шаг графика">
      <button
        type="button"
        class="pill pill--quiet"
        :class="{ 'pill--active': !filters.granularity }"
        :aria-pressed="!filters.granularity"
        @click="selectGranularity(undefined)"
      >
        Авто
      </button>

      <button
        v-for="step in granularities"
        :key="step"
        type="button"
        class="pill pill--quiet"
        :class="{ 'pill--active': filters.granularity === step }"
        :aria-pressed="filters.granularity === step"
        @click="selectGranularity(step)"
      >
        {{ GRANULARITY_LABELS[step] }}
      </button>
    </div>

    <div v-for="kind in lists" :key="kind" class="dropdown">
      <button
        type="button"
        class="pill pill--quiet"
        :class="{ 'pill--active': filters[kind].length > 0 }"
        :aria-expanded="openList === kind"
        @click="openList = openList === kind ? null : kind"
      >
        {{ summary(kind) }}
        <span aria-hidden="true">▾</span>
      </button>

      <div v-if="openList === kind" class="dropdown__panel">
        <button
          v-if="filters[kind].length"
          type="button"
          class="dropdown__clear"
          @click="clear(kind)"
        >
          Сбросить выбор
        </button>

        <label
          v-for="option in directory?.[kind] ?? []"
          :key="option.value"
          class="option"
        >
          <input
            type="checkbox"
            :checked="filters[kind].includes(option.value)"
            @change="toggleValue(kind, option.value)"
          >
          <span class="option__name">{{ option.value }}</span>
          <!-- Оборот за значением: крупные склады встают выше мелких, и
               выбирать приходится не по алфавиту. -->
          <span class="option__meta">{{ formatCompactMoney(option.revenue) }}</span>
        </label>
      </div>
    </div>

    <!-- Возврат уменьшает выручку, поэтому по умолчанию он в ней есть. Снимают
         его, когда нужно увидеть отгрузку саму по себе. -->
    <button
      type="button"
      class="pill pill--quiet"
      :class="{ 'pill--active': !filters.withReturns }"
      :aria-pressed="!filters.withReturns"
      @click="toggleReturns"
    >
      {{ filters.withReturns ? 'С возвратами' : 'Без возвратов' }}
    </button>

    <button v-if="hasSelection" type="button" class="reset" @click="reset">
      Сбросить фильтры
    </button>
  </div>
</template>

<style scoped>
.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.75rem;
  margin-bottom: 1.25rem;
}

.filters__group {
  display: flex;
  gap: 0.3rem;
  flex-wrap: wrap;
}

.pill {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.42rem 0.85rem;
  border: 0;
  border-radius: var(--radius-pill);
  background: var(--color-surface-raised);
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.8rem;
  cursor: pointer;
  white-space: nowrap;
  transition: background 0.12s ease, color 0.12s ease;
}

.pill:hover {
  background: var(--color-surface-sunken);
  color: var(--color-text);
}

.pill--quiet {
  background: transparent;
  box-shadow: inset 0 0 0 1px var(--color-border);
}

/* Акцент означает выбранное — и только это. Данные им не красятся. */
.pill--active,
.pill--active:hover {
  background: var(--color-accent);
  color: var(--color-accent-text);
  box-shadow: none;
}

.dropdown {
  position: relative;
}

.dropdown__panel {
  position: absolute;
  top: calc(100% + 0.4rem);
  left: 0;
  z-index: 20;
  width: max-content;
  min-width: 16rem;
  max-width: 24rem;
  max-height: 20rem;
  overflow-y: auto;
  padding: 0.4rem;
  background: var(--color-surface-raised);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-lg);
}

.dropdown__clear {
  width: 100%;
  padding: 0.4rem 0.5rem;
  margin-bottom: 0.2rem;
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.78rem;
  text-align: left;
  cursor: pointer;
}

.dropdown__clear:hover {
  background: var(--color-surface-sunken);
  color: var(--color-text);
}

.option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.4rem 0.5rem;
  border-radius: var(--radius-sm);
  font-size: 0.8rem;
  cursor: pointer;
}

.option:hover {
  background: var(--color-surface-sunken);
}

.option__name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.option__meta {
  margin-left: auto;
  flex-shrink: 0;
  color: var(--color-text-faint);
  font-size: 0.74rem;
  font-variant-numeric: tabular-nums;
}

.dropdown__panel--dates {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  min-width: 13rem;
  padding: 0.75rem;
}

.date {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.78rem;
}

.date__label {
  width: 1.5rem;
  color: var(--color-text-muted);
}

.date__input {
  flex: 1;
  min-width: 0;
  padding: 0.35rem 0.5rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--control-surface);
  color: var(--color-text);
  font: inherit;
  font-size: 0.78rem;
}

.date__actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.15rem;
}

.date__apply {
  flex: 1;
  padding: 0.4rem 0.75rem;
  border: 0;
  border-radius: var(--radius-pill);
  background: var(--color-accent);
  color: var(--color-accent-text);
  font: inherit;
  font-size: 0.78rem;
  cursor: pointer;
}

.date__apply:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.date__actions .dropdown__clear {
  width: auto;
  margin: 0;
  white-space: nowrap;
}

.reset {
  border: 0;
  background: none;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.78rem;
  text-decoration: underline;
  cursor: pointer;
  padding: 0;
}

.reset:hover {
  color: var(--color-text);
}
</style>