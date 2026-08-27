<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'courses.view' })
useHead({ title: 'Регламенты' })

const { can } = useAuth()
const { fetchRegulations, fetchCategories } = useRegulationsApi()

const search = ref('')
const category = ref<string>('')

const { data, pending, error, refresh } = await useAsyncData(
  'lms.regulations',
  () => fetchRegulations({
    search: search.value || undefined,
    category: category.value || undefined,
  }),
  { watch: [category] },
)

const { data: categoryData } = await useAsyncData('lms.regulation-categories', () => fetchCategories())

const regulations = computed(() => data.value?.data ?? [])
const categories = computed(() => categoryData.value?.data ?? [])

/** Плоский список для фильтра: список не умеет вкладываться. */
const options = computed(() => {
  const flat: { value: string, label: string }[] = [{ value: '', label: 'Все категории' }]

  const walk = (nodes: typeof categories.value, depth: number) => {
    for (const node of nodes) {
      flat.push({ value: node.slug, label: '‒'.repeat(depth) + (depth ? ' ' : '') + node.name })
      walk(node.children ?? [], depth + 1)
    }
  }

  walk(categories.value, 0)

  return flat
})

let timer: ReturnType<typeof setTimeout> | undefined

// С задержкой: спрашивать сервер на каждую букву незачем.
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => void refresh(), 300)
})

onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Регламенты
        </h1>
        <p class="page-subtitle">
          Правила, по которым работают. Каждое — на одну страницу, с отметкой об ознакомлении.
        </p>
      </div>

      <NuxtLink v-if="can('courses.create')" to="/lms/regulations/new" class="button-primary">
        Новый регламент
      </NuxtLink>
    </header>

    <div class="filters">
      <input
        v-model.trim="search"
        class="input"
        type="search"
        placeholder="Название или описание"
        aria-label="Поиск по регламентам"
      >

      <select v-model="category" class="input filters__category" aria-label="Категория">
        <option v-for="option in options" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>
    </div>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось загрузить регламенты.
    </p>

    <div v-else-if="pending" class="stack">
      <div v-for="n in 3" :key="n" class="card row">
        <div class="skeleton skeleton-line" />
      </div>
    </div>

    <UiEmptyState
      v-else-if="!regulations.length"
      title="Регламентов пока нет"
      :description="can('courses.create')
        ? 'Заведите первый — он будет виден всем, кто читает базу знаний.'
        : 'Когда правила появятся, они будут здесь.'"
    >
      <NuxtLink v-if="can('courses.create')" to="/lms/regulations/new" class="button-primary">
        Новый регламент
      </NuxtLink>
    </UiEmptyState>

    <div v-else class="stack">
      <NuxtLink
        v-for="item in regulations"
        :key="item.id"
        :to="`/lms/regulations/${item.slug}`"
        class="card row"
      >
        <div class="row__body">
          <span class="row__title">{{ item.title }}</span>
          <span v-if="item.summary" class="faint">{{ item.summary }}</span>
          <span v-if="item.category" class="faint row__category">{{ item.category.name }}</span>
        </div>

        <span v-if="!item.is_published" class="badge badge--warning">{{ item.status_label }}</span>
        <span v-if="item.is_private" class="badge" title="Виден только допущенным">Закрыт</span>
        <span v-if="item.is_acknowledged" class="badge badge--success">Ознакомлен</span>
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
  margin-bottom: 1.5rem;
}

.head a {
  text-decoration: none;
}

.filters {
  display: flex;
  gap: 0.6rem;
  margin-bottom: 1.25rem;
}

.filters__category {
  flex: 0 0 14rem;
}

.stack {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.9rem 1.1rem;
  color: inherit;
  text-decoration: none;
  transition: box-shadow 0.15s ease;
}

.row:hover {
  box-shadow: var(--shadow-md);
}

.row__body {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  gap: 0.1rem;
  font-size: 0.9rem;
}

.row__title {
  font-weight: 550;
}

.row__category {
  font-size: 0.825rem;
}

.skeleton-line {
  width: 100%;
  height: 1.5rem;
}

@media (max-width: 40rem) {
  .filters {
    flex-direction: column;
  }

  .filters__category {
    flex: 1 1 auto;
  }
}

@media (prefers-reduced-motion: reduce) {
  .row { transition: none; }
}
</style>
