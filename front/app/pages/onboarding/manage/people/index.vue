<script setup lang="ts">
/**
 * Сотрудники и их маршруты.
 *
 * Назначений здесь нет: маршрут у каждого свой и заводится сам. Поэтому
 * список — это список людей, у кого-то с пустой картой, и открывают его,
 * чтобы найти человека и положить в его маршрут первый этап.
 */
definePageMeta({ middleware: 'auth', permission: 'onboarding.manage' })
useHead({ title: 'Адаптация — сотрудники' })

const api = useOnboardingApi()

const search = ref('')

const { data, pending, error } = await useAsyncData(
  'onboarding.journeys',
  () => api.fetchJourneys(search.value),
  // Список перезапрашивается на каждый вдумчивый ввод, а не на каждую букву:
  // поиск идёт по базе, и дребезг гасится задержкой ниже.
  { watch: [search] },
)

const rows = computed(() => data.value?.data ?? [])

/** Сначала те, у кого маршрут идёт: пустые карты — хвостом. */
const ordered = computed(() => [...rows.value].sort((a, b) => {
  const weight = (row: typeof a) => row.progress.total === 0 ? 2 : (row.completed_at ? 1 : 0)

  return weight(a) - weight(b)
}))

function day(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU') : '—'
}
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Сотрудники
        </h1>
        <p class="page-subtitle">
          У каждого свой маршрут — поначалу пустой. Откройте человека, чтобы
          собрать маршрут руками или наложить шаблон.
        </p>
      </div>
    </header>

    <div class="card search">
      <label class="field-label" for="s-people">Найти сотрудника</label>
      <input
        id="s-people"
        v-model.trim="search"
        class="input"
        type="search"
        placeholder="Фамилия или имя"
        autocomplete="off"
      >
      <p class="faint search__note">
        Ищет по фамилии, имени и почте — и понимает латинскую раскладку.
      </p>
    </div>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось загрузить список.
    </p>

    <div v-else-if="pending" class="stack">
      <div v-for="n in 3" :key="n" class="card row">
        <div class="skeleton skeleton-line" />
      </div>
    </div>

    <UiEmptyState
      v-else-if="!ordered.length"
      title="Никого не нашли"
      description="Проверьте фамилию — или человек уже уволен."
    />

    <div v-else class="stack">
      <NuxtLink
        v-for="row in ordered"
        :key="row.employee.id"
        :to="`/onboarding/manage/people/${row.employee.id}`"
        class="card row"
      >
        <div class="row__body">
          <span class="row__title">{{ row.employee.name }}</span>
          <span class="faint">
            <template v-if="row.employee.job_title">{{ row.employee.job_title }} · </template>
            <template v-if="row.progress.total === 0">маршрут пуст</template>
            <template v-else>
              с {{ day(row.started_at) }}<template v-if="row.current_day"> · день {{ row.current_day }}</template>
            </template>
          </span>
        </div>

        <div v-if="row.progress.total > 0" class="row__progress">
          <UiProgressBar :value="row.progress.percent" />
          <span class="faint">{{ row.progress.completed }}/{{ row.progress.total }}</span>
        </div>

        <span
          v-if="row.status_label && row.progress.total > 0"
          class="badge"
          :class="{
            'badge--success': row.status === 'completed',
            'badge--warning': row.status === 'paused',
          }"
        >
          {{ row.status_label }}
        </span>
        <span v-else class="badge">Пусто</span>
      </NuxtLink>
    </div>
  </section>
</template>

<style scoped>
.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.25rem;
  flex-wrap: wrap;
}

.head a {
  text-decoration: none;
}


.search {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 1rem 1.25rem;
  margin-bottom: 1.25rem;
  max-width: 32rem;
}

.search__note {
  margin: 0;
  font-size: 0.82rem;
}

.stack {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.row {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem 1.25rem;
  color: inherit;
  text-decoration: none;
}

.row__body {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  flex: 1;
  min-width: 0;
}

.row__title {
  font-weight: 600;
}

.row__progress {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  min-width: 10rem;
}

@media (max-width: 34rem) {
  .row {
    flex-wrap: wrap;
  }
}
</style>
