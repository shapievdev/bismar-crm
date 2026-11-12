<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'onboarding.manage' })
useHead({ title: 'Адаптация — отчёт' })

const api = useOnboardingApi()
const { fetchStructure } = useStructureApi()

const filters = reactive({
  from: '',
  to: '',
  template_id: '',
  version_id: '',
  store: '',
  department_id: '',
})

const { data, pending, error, refresh } = await useAsyncData('onboarding.report', () => api.fetchReport({
  from: filters.from || undefined,
  to: filters.to || undefined,
  template_id: filters.template_id || undefined,
  version_id: filters.version_id || undefined,
  store: filters.store || undefined,
  department_id: filters.department_id || undefined,
}), { watch: [filters] })

const report = computed(() => data.value?.data ?? null)

const { data: templatesData } = await useAsyncData('onboarding.templates', () => api.fetchTemplates())
const { data: structureData } = await useAsyncData('onboarding.report.structure', async () => {
  try {
    return (await fetchStructure()).data
  }
  catch {
    return []
  }
})

const versionOptions = computed(() => [{ value: '', label: 'Все версии' }, ...(templatesData.value?.data ?? []).map(t => ({ value: String(t.id), label: `${t.title} · v${t.version}` }))])
const templateOptions = computed(() => [
  { value: '', label: 'Все маршруты' },
  ...(templatesData.value?.data ?? []).filter(t => !t.parent_id).map(template => ({
    value: String(template.id),
    label: `${template.title} · v${template.version}`,
  })),
])

const departmentOptions = computed(() => {
  const options: { value: string, label: string }[] = [{ value: '', label: 'Вся компания' }]

  const walk = (nodes: { id: number, name: string, children?: unknown[] }[], depth: number) => {
    for (const node of nodes) {
      options.push({ value: String(node.id), label: `${'— '.repeat(depth)}${node.name}` })
      walk((node.children ?? []) as { id: number, name: string, children?: unknown[] }[], depth + 1)
    }
  }

  walk((structureData.value ?? []) as { id: number, name: string, children?: unknown[] }[], 0)

  return options
})

function formatOrDash(value: number | null, suffix = ''): string {
  return value === null ? '—' : `${value}${suffix}`
}
</script>

<template>
  <section>
    <header class="head">
      <h1 class="page-title">
        Отчёт по адаптации
      </h1>
      <p class="page-subtitle">
        Период считается по дате старта маршрута. Пустые маршруты не в счёт.
      </p>
    </header>

    <!-- Фильтры. -->
    <div class="card filters">
      <div class="field">
        <label class="field-label" for="r-from">С</label>
        <input id="r-from" v-model="filters.from" type="date" class="input">
      </div>
      <div class="field">
        <label class="field-label" for="r-to">По</label>
        <input id="r-to" v-model="filters.to" type="date" class="input">
      </div>
      <div class="field">
        <label class="field-label" for="r-template">Маршрут</label>
        <UiSelect id="r-template" v-model="filters.template_id" :options="templateOptions" />
      </div>
      <div class="field"><label class="field-label" for="r-version">Точная версия</label><UiSelect id="r-version" v-model="filters.version_id" :options="versionOptions" /></div>
      <div class="field"><label class="field-label" for="r-store">Магазин</label><input id="r-store" v-model.trim="filters.store" class="input" placeholder="Все магазины"></div>
      <div class="field">
        <label class="field-label" for="r-department">Подразделение</label>
        <UiSelect id="r-department" v-model="filters.department_id" :options="departmentOptions" searchable />
      </div>
    </div>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось построить отчёт.
    </p>

    <div v-else-if="pending" class="card">
      <div class="skeleton skeleton-line" />
    </div>

    <template v-else-if="report">
      <div class="tiles">
        <div class="card tile">
          <span class="tile__value">{{ report.started }}</span>
          <span class="tile__label">Начали</span>
        </div>
        <div class="card tile">
          <span class="tile__value">{{ report.completed }}</span>
          <span class="tile__label">Завершили</span>
        </div>
        <div class="card tile">
          <span class="tile__value">{{ formatOrDash(report.completion_rate, '%') }}</span>
          <span class="tile__label">Доля завершивших</span>
        </div>
        <div class="card tile">
          <span class="tile__value">{{ formatOrDash(report.average_days) }}</span>
          <span class="tile__label">Среднее время, дней</span>
        </div>
        <div class="card tile">
          <span class="tile__value">{{ formatOrDash(report.exam_first_try_rate, '%') }}</span>
          <span class="tile__label">Экзамен с 1-й попытки</span>
        </div>
        <div class="card tile">
          <span class="tile__value">{{ formatOrDash(report.exam_average_attempts) }}</span>
          <span class="tile__label">Попыток на экзамен в среднем</span>
        </div>
        <div class="card tile">
          <span class="tile__value">{{ formatOrDash(report.average_days_to_access) }}</span>
          <span class="tile__label">Дней до допуска</span>
        </div>
      </div>

      <p class="faint">Всего попыток итогового экзамена: {{ report.exam_attempts }}</p>
      <section class="card slow">
        <h2 class="slow__title">
          Этапы с наибольшими задержками
        </h2>
        <p v-if="!report.slowest_stages.length" class="faint">
          Пока нечего показать.
        </p>
        <div v-else class="table-scroll">
          <table class="table">
            <thead>
              <tr>
                <th>Этап</th>
                <th>Дней на выполнение</th><th>Просрочка, дней</th>
                <th>Стажировок</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in report.slowest_stages" :key="row.title">
                <td>{{ row.title }}</td>
                <td>{{ row.average_days }}</td><td>{{ row.average_delay_days }}</td>
                <td>{{ row.journeys }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.25rem;
}


.filters {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
  padding: 1rem 1.25rem;
  margin-bottom: 1.25rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  min-width: 11rem;
}

.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
  gap: 0.75rem;
  margin-bottom: 1.25rem;
}

.tile {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 1rem 1.2rem;
}

.tile__value {
  font-size: 1.5rem;
  font-weight: 700;
  line-height: 1.1;
}

.tile__label {
  font-size: 0.82rem;
  color: var(--color-text-faint);
}

.slow {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1.2rem 1.4rem;
}

.slow__title {
  margin: 0;
  font-size: 1rem;
}

.table-scroll {
  overflow-x: auto;
}

.table {
  width: 100%;
  border-collapse: collapse;
}

.table th,
.table td {
  padding: 0.5rem 0.75rem;
  text-align: left;
  border-bottom: 1px solid var(--color-border);
  white-space: nowrap;
}

.table th {
  font-weight: 500;
  color: var(--color-text-faint);
}
</style>
