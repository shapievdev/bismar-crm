<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'knowledge.view' })
useHead({ title: 'База знаний' })

const { fetchArticles, fetchCategories } = useKnowledgeApi()
const { can } = useAuth()
const route = useRoute()
const router = useRouter()

const search = ref(typeof route.query.search === 'string' ? route.query.search : '')
const category = ref(typeof route.query.category === 'string' ? route.query.category : '')

const { data: categories } = await useAsyncData('knowledge.categories', () => fetchCategories())

const { data, pending, error } = await useAsyncData(
  'knowledge.articles',
  () => fetchArticles({
    search: search.value || undefined,
    category: category.value || undefined,
  }),
  { watch: [search, category] },
)

// Keep the URL in step so a filtered list can be shared or reloaded.
watchEffect(() => {
  router.replace({
    query: {
      ...(search.value ? { search: search.value } : {}),
      ...(category.value ? { category: category.value } : {}),
    },
  })
})

const articles = computed(() => data.value?.data ?? [])

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleDateString('ru-RU') : '—'
}
</script>

<template>
  <section>
    <header class="page-header">
      <div>
        <h1>База знаний</h1>
        <p class="muted">
          Инструкции и регламенты команды.
        </p>
      </div>

      <NuxtLink v-if="can('knowledge.create')" to="/knowledge/new" class="button-primary">
        Новая статья
      </NuxtLink>
    </header>

    <div class="filters">
      <input
        v-model.trim="search"
        type="search"
        placeholder="Поиск по статьям…"
        aria-label="Поиск по статьям"
      >

      <select v-model="category" aria-label="Категория">
        <option value="">
          Все категории
        </option>
        <option
          v-for="item in categories?.data ?? []"
          :key="item.slug"
          :value="item.slug"
        >
          {{ item.name }} ({{ item.articles_count ?? 0 }})
        </option>
      </select>
    </div>

    <p v-if="error" class="auth-alert" role="alert">
      Не удалось загрузить статьи.
    </p>

    <p v-else-if="pending" class="muted">
      Загрузка…
    </p>

    <p v-else-if="!articles.length" class="empty">
      {{ search || category ? 'Ничего не найдено. Попробуйте изменить запрос.' : 'Статей пока нет.' }}
    </p>

    <ul v-else class="articles">
      <li v-for="article in articles" :key="article.slug" class="article">
        <NuxtLink :to="`/knowledge/${article.slug}`" class="article__title">
          {{ article.title }}
        </NuxtLink>

        <p v-if="article.excerpt" class="article__excerpt">
          {{ article.excerpt }}
        </p>

        <div class="article__meta">
          <span v-if="article.status !== 'published'" class="badge badge--muted">
            {{ article.status_label }}
          </span>
          <span v-if="article.category">{{ article.category.name }}</span>
          <span v-if="article.author">{{ article.author.name }}</span>
          <span>{{ formatDate(article.published_at ?? article.updated_at) }}</span>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.25rem;
}

.page-header h1 {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
}

.muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-bottom: 1.25rem;
}

.filters input,
.filters select {
  padding: 0.5rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
}

.filters input {
  flex: 1;
  min-width: 14rem;
}

.empty {
  padding: 2rem;
  border: 1px dashed var(--color-border);
  border-radius: var(--radius);
  color: var(--color-text-muted);
  text-align: center;
}

.articles {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.article {
  padding: 1rem 1.25rem;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}

.article__title {
  font-size: 1.05rem;
  font-weight: 500;
  color: inherit;
  text-decoration: none;
}

.article__title:hover {
  color: var(--color-accent);
}

.article__excerpt {
  margin: 0.35rem 0 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.article__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin-top: 0.6rem;
  color: var(--color-text-muted);
  font-size: 0.8rem;
}

.badge--muted {
  padding: 0.05rem 0.45rem;
  border: 1px solid var(--color-border);
  border-radius: 999px;
}
</style>
