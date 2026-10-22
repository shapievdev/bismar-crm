<script setup lang="ts">
import type { SelectOption } from '~/components/ui/Select.vue'
import type { SellerRow } from '~/composables/useSalesApi'
import { formatCompactMoney, formatMoney, formatNumber, formatPercent } from '~/utils/numbers'

/**
 * Конструктор: чья выручка чья.
 *
 * В витрине продавец — строка с приставкой отдела и полным отчеством, «ЛКМ
 * Агабекова Света Шагабудиновна». В базе знаний тот же человек заведён как
 * «Агабекова Света» и стоит в отделе ЛКМ. Общего ключа у 1С и у нас нет, и
 * связать одно с другим может только человек.
 *
 * Поэтому экран устроен как работа, а не как справочник: список отсортирован по
 * обороту — начинают с тех, за кем миллионы, — сверху видно, сколько выручки
 * ещё ничьё, а подсказки разбирают добрую половину имён сами. Подсказка не
 * применяется молча: по ней нажимают.
 */
definePageMeta({ middleware: 'auth', permission: 'sales-plans.manage' })

useHead({ title: 'Продавцы и отделы' })

const { fetchSellers, saveSeller, acceptSuggestions } = useSalesApi()
const { confirm } = useAppDialog()

/*
 * `deep: true` здесь не украшение. Правка пишется прямо в строку списка, а
 * обычная неглубокая ссылка такой правки не замечает: сохранённый продавец
 * остался бы висеть среди неразобранных до перезагрузки страницы.
 */
const { data, pending, refresh } = await useAsyncData(
  'sales.sellers',
  async () => (await fetchSellers()).data,
  { deep: true },
)

const directory = computed(() => data.value)
const sellers = computed<SellerRow[]>(() => directory.value?.sellers ?? [])

/** Что показывать: всё, только неразобранное или только разобранное. */
type SellerView = 'all' | 'open' | 'done'

const view = ref<SellerView>('open')
const search = ref('')

const visible = computed<SellerRow[]>(() => {
  const needle = search.value.trim().toLowerCase()

  return sellers.value.filter((seller) => {
    const assigned = seller.department_id !== null

    if (view.value === 'open' && assigned) {
      return false
    }

    if (view.value === 'done' && !assigned) {
      return false
    }

    return needle === '' || seller.external_name.toLowerCase().includes(needle)
  })
})

/*
 * Нераспределённое считается здесь, а не берётся из ответа сервера: человек
 * разбирает список подряд, и число, обновляющееся только на перезагрузку, не
 * показывало бы ему, что работа движется.
 */
const unassigned = computed(() => {
  const revenue = sellers.value
    .filter(seller => seller.department_id === null)
    .reduce((sum, seller) => sum + seller.revenue, 0)

  const total = directory.value?.totals.revenue ?? 0

  return {
    revenue,
    share: total > 0 ? Math.round((revenue / total) * 1000) / 10 : 0,
  }
})

const departmentOptions = computed<SelectOption<number | null>[]>(() => [
  { value: null, label: 'Отдел не выбран' },
  ...(directory.value?.departments ?? []).map(department => ({
    value: department.id,
    label: department.name,
    hint: department.path,
  })),
])

const peopleOptions = computed<SelectOption<number | null>[]>(() => [
  { value: null, label: 'Человека нет' },
  ...(directory.value?.people ?? []).map(person => ({
    value: person.id,
    label: person.name,
    hint: person.job_title ?? undefined,
  })),
])

const departmentName = (id: number | null): string =>
  (directory.value?.departments ?? []).find(item => item.id === id)?.name ?? ''

const personName = (id: number | null): string =>
  (directory.value?.people ?? []).find(item => item.id === id)?.name ?? ''

/*
 * Правка уходит на сервер сразу, без кнопки «сохранить».
 *
 * Строк под две сотни, и общая кнопка означала бы, что закрытая на середине
 * вкладка теряет час работы. Пишется ровно та строка, которую тронули, —
 * остальные сервер и не видит.
 */
const saving = ref<string | null>(null)
const failed = ref<string | null>(null)

async function assign(seller: SellerRow, patch: Partial<Pick<SellerRow, 'department_id' | 'user_id'>>) {
  const next = { ...seller, ...patch }

  saving.value = seller.external_name
  failed.value = null

  try {
    await saveSeller({
      external_name: seller.external_name,
      department_id: next.department_id,
      user_id: next.user_id,
    })

    Object.assign(seller, patch)
  }
  catch {
    failed.value = seller.external_name
  }
  finally {
    saving.value = null
  }
}

const accepting = ref(false)

/** Сколько строк разберёт одно нажатие — считается до того, как нажали. */
const suggestable = computed(() =>
  sellers.value.filter(seller =>
    seller.department_id === null
    && seller.user_id === null
    && (seller.suggestion.department_id !== null || seller.suggestion.user_id !== null),
  ).length,
)

async function acceptAll() {
  const agreed = await confirm({
    title: 'Принять подсказки?',
    message: `Разберём ${suggestable.value} имён по фамилии и приставке отдела. Сопоставленное руками останется как есть: подсказка не переписывает сделанное.`,
    confirmLabel: 'Принять',
  })

  if (!agreed) {
    return
  }

  accepting.value = true

  try {
    await acceptSuggestions()
    await refresh()
  }
  finally {
    accepting.value = false
  }
}
</script>

<template>
  <div class="page">
    <header class="page__head">
      <h1 class="page__title">
        Продавцы и отделы
      </h1>
      <p class="page__lead">
        Витрина знает продавца по имени из 1С, база знаний — по человеку в структуре. Связать их
        может только человек: общего ключа у них нет. Пока имя не разобрано, его выручка в отчёте
        по отделам стоит нераспределённой.
      </p>
    </header>

    <section v-if="directory" class="summary">
      <div class="summary__tile">
        <span class="summary__label">Имён в выгрузке</span>
        <strong class="summary__value">{{ formatNumber(directory.totals.sellers) }}</strong>
        <span class="summary__hint">за последние {{ directory.lookback_days }} дней</span>
      </div>
      <div class="summary__tile">
        <span class="summary__label">Оборот</span>
        <strong class="summary__value">{{ formatCompactMoney(directory.totals.revenue) }}</strong>
        <span class="summary__hint">{{ formatMoney(directory.totals.revenue) }}</span>
      </div>
      <div class="summary__tile" :class="{ 'summary__tile--warn': unassigned.share > 0 }">
        <span class="summary__label">Не распределено</span>
        <strong class="summary__value">{{ formatCompactMoney(unassigned.revenue) }}</strong>
        <span class="summary__hint">{{ formatPercent(unassigned.share) }} оборота</span>
      </div>
      <div v-if="suggestable > 0" class="summary__tile summary__tile--action">
        <span class="summary__label">Подсказок готово</span>
        <strong class="summary__value">{{ formatNumber(suggestable) }}</strong>
        <button type="button" class="accept" :disabled="accepting" @click="acceptAll">
          {{ accepting ? 'Разбираем…' : 'Принять все' }}
        </button>
      </div>
    </section>

    <div class="toolbar">
      <div class="switch">
        <button
          v-for="option in [
            { value: 'open', label: 'Неразобранные' },
            { value: 'done', label: 'Разобранные' },
            { value: 'all', label: 'Все' },
          ]"
          :key="option.value"
          type="button"
          class="switch__item"
          :class="{ 'switch__item--active': view === option.value }"
          @click="view = option.value as SellerView"
        >
          {{ option.label }}
        </button>
      </div>

      <input v-model="search" type="search" class="search" placeholder="Найти имя">
    </div>

    <p v-if="failed" class="notice notice--error">
      Не удалось сохранить «{{ failed }}». Попробуйте ещё раз.
    </p>

    <p v-if="pending" class="loading">
      Загружаем…
    </p>

    <UiEmptyState
      v-else-if="visible.length === 0"
      title="Пусто"
      :description="view === 'open' ? 'Все имена разобраны.' : 'Ничего не нашлось.'"
    />

    <ul v-else class="sellers">
      <li v-for="seller in visible" :key="seller.external_name" class="seller">
        <div class="seller__who">
          <span class="seller__name">{{ seller.external_name }}</span>
          <span class="seller__fact">
            {{ formatCompactMoney(seller.revenue) }} · {{ formatNumber(seller.orders) }} заказов
          </span>
        </div>

        <div class="seller__field">
          <UiSelect
            :model-value="seller.department_id"
            :options="departmentOptions"
            :disabled="saving === seller.external_name"
            searchable
            @update:model-value="value => assign(seller, { department_id: value })"
          />
          <!-- Подсказка остаётся на виду и после сопоставления: по ней видно,
               что человека перевели в другой отдел структуры. -->
          <button
            v-if="seller.suggestion.department_id !== null && seller.suggestion.department_id !== seller.department_id"
            type="button"
            class="hint"
            @click="assign(seller, { department_id: seller.suggestion.department_id })"
          >
            → {{ departmentName(seller.suggestion.department_id) }}
          </button>
        </div>

        <div class="seller__field">
          <UiSelect
            :model-value="seller.user_id"
            :options="peopleOptions"
            :disabled="saving === seller.external_name"
            searchable
            @update:model-value="value => assign(seller, { user_id: value })"
          />
          <button
            v-if="seller.suggestion.user_id !== null && seller.suggestion.user_id !== seller.user_id"
            type="button"
            class="hint"
            @click="assign(seller, { user_id: seller.suggestion.user_id })"
          >
            → {{ personName(seller.suggestion.user_id) }}
          </button>
        </div>
      </li>
    </ul>
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
  max-width: 68ch;
}

.summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
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

.summary__tile--warn .summary__value {
  color: var(--color-danger);
}

.summary__tile--action {
  gap: 0.45rem;
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

.summary__hint {
  font-size: 0.72rem;
  color: var(--color-text-faint);
}

.accept {
  align-self: flex-start;
  padding: 0.3rem 0.7rem;
  border: 0;
  border-radius: var(--radius-sm);
  background: var(--color-text);
  color: var(--color-bg);
  font: inherit;
  font-size: 0.76rem;
  cursor: pointer;
}

.accept:disabled {
  opacity: 0.5;
  cursor: default;
}

.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.switch {
  display: flex;
  gap: 0.25rem;
}

.switch__item {
  padding: 0.3rem 0.7rem;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.78rem;
  cursor: pointer;
}

.switch__item--active {
  background: var(--color-surface-sunken);
  color: var(--color-text);
}

.search {
  min-width: 14rem;
  padding: 0.4rem 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  font-size: 0.82rem;
}

.sellers {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.seller {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 1fr);
  align-items: start;
  gap: 0.75rem;
  padding: 0.6rem 0.85rem;
  border-radius: var(--radius-sm);
  background: var(--color-surface);
}

.seller__who {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
}

.seller__name {
  font-size: 0.85rem;
  overflow-wrap: anywhere;
}

.seller__fact {
  font-size: 0.72rem;
  color: var(--color-text-faint);
  font-variant-numeric: tabular-nums;
}

.seller__field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 0;
}

.hint {
  align-self: flex-start;
  padding: 0;
  border: 0;
  background: none;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.72rem;
  text-align: left;
  cursor: pointer;
}

.hint:hover {
  color: var(--color-text);
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

@media (max-width: 60rem) {
  .seller {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
