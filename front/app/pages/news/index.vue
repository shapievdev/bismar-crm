<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Новости' })

const { can } = useAuth()
const { fetchFeed, fetchAll } = useNewsApi()

/**
 * Ведущему новости — всё, включая черновики; остальным — то, что им адресовано.
 * Два разных маршрута, а не один с флажком: забытая проверка права на сервере
 * иначе показала бы черновик всей компании.
 */
const isEditor = computed(() => can('news.manage'))

const { data, pending, error } = await useAsyncData(
  'news.list',
  () => isEditor.value ? fetchAll() : fetchFeed(),
)

const news = computed(() => data.value?.data ?? [])

function day(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU') : '—'
}
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Новости
        </h1>
        <p class="page-subtitle">
          {{ isEditor ? 'Всё написанное, включая черновики.' : 'Всё, что вам адресовали.' }}
        </p>
      </div>

      <NuxtLink v-if="isEditor" to="/news/new" class="button-primary">
        Написать новость
      </NuxtLink>
    </header>

    <p v-if="error" class="alert alert--danger" role="alert">
      Не удалось загрузить список.
    </p>

    <div v-else-if="pending" class="stack">
      <div v-for="n in 3" :key="n" class="card row">
        <div class="skeleton skeleton-line" />
      </div>
    </div>

    <UiEmptyState
      v-else-if="!news.length"
      title="Новостей пока нет"
      :description="isEditor ? 'Напишите первую.' : 'Когда что-то появится, оно будет здесь.'"
    />

    <div v-else class="stack">
      <NuxtLink
        v-for="item in news"
        :key="item.id"
        :to="isEditor ? `/news/${item.slug}/edit` : `/news/${item.slug}`"
        class="card row"
      >
        <div class="row__body">
          <span class="row__title">{{ item.title }}</span>
          <span class="faint">
            {{ day(item.published_at) }}<template v-if="item.author"> · {{ item.author.name }}</template>
          </span>
        </div>

        <span v-if="item.is_pinned" class="badge">Закреплена</span>
        <span v-if="!item.is_published" class="badge badge--warning">Черновик</span>
        <span
          v-if="isEditor && item.audience_size !== undefined"
          class="faint row__count"
          title="Ознакомились из числа адресатов"
        >
          {{ item.acknowledged_count ?? 0 }} / {{ item.audience_size }}
        </span>
        <span v-else-if="item.is_acknowledged" class="badge badge--success">Ознакомлен</span>
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
  margin-bottom: 1.75rem;
}

.head a {
  text-decoration: none;
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

.row__count {
  font-variant-numeric: tabular-nums;
}

.skeleton-line {
  width: 100%;
  height: 1.5rem;
}

@media (prefers-reduced-motion: reduce) {
  .row { transition: none; }
}
</style>
