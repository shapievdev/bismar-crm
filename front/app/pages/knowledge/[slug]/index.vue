<script setup lang="ts">
definePageMeta({ middleware: 'auth', permission: 'knowledge.view' })

const route = useRoute()
const router = useRouter()
const { fetchArticle, deleteArticle } = useKnowledgeApi()
const { can } = useAuth()

const slug = computed(() => String(route.params.slug))

const { data, error } = await useAsyncData(
  () => `knowledge.article.${slug.value}`,
  () => fetchArticle(slug.value),
)

if (error.value) {
  // A draft the reader may not see answers 404, same as a missing article.
  throw createError({
    statusCode: 404,
    statusMessage: 'Статья не найдена',
    fatal: true,
  })
}

const article = computed(() => data.value?.data)

useHead(() => ({ title: article.value?.title ?? 'Статья' }))

/** The body is plain text; blank lines separate paragraphs. */
const paragraphs = computed(() =>
  (article.value?.content ?? '')
    .split(/\n{2,}/)
    .map(block => block.trim())
    .filter(Boolean),
)

const isDeleting = ref(false)
const deleteError = ref<string | null>(null)

async function remove() {
  isDeleting.value = true
  deleteError.value = null

  try {
    await deleteArticle(slug.value)
    await router.push('/knowledge')
  }
  catch {
    deleteError.value = 'Не удалось удалить статью.'
  }
  finally {
    isDeleting.value = false
  }
}

function formatDate(value: string | null | undefined): string {
  return value ? new Date(value).toLocaleDateString('ru-RU') : '—'
}
</script>

<template>
  <article v-if="article">
    <NuxtLink to="/knowledge" class="back">
      ← К списку
    </NuxtLink>

    <header class="header">
      <h1>{{ article.title }}</h1>

      <div class="meta">
        <span v-if="article.status !== 'published'" class="badge">{{ article.status_label }}</span>
        <span v-if="article.category">{{ article.category.name }}</span>
        <span v-if="article.author">{{ article.author.name }}</span>
        <span>{{ formatDate(article.published_at ?? article.updated_at) }}</span>
      </div>
    </header>

    <p v-if="article.excerpt" class="excerpt">
      {{ article.excerpt }}
    </p>

    <div class="content">
      <p v-for="(paragraph, index) in paragraphs" :key="index">
        {{ paragraph }}
      </p>
    </div>

    <footer v-if="can('knowledge.update') || can('knowledge.delete')" class="actions">
      <NuxtLink
        v-if="can('knowledge.update')"
        :to="`/knowledge/${article.slug}/edit`"
        class="button-primary"
      >
        Редактировать
      </NuxtLink>

      <button
        v-if="can('knowledge.delete')"
        type="button"
        class="button-danger"
        :disabled="isDeleting"
        @click="remove"
      >
        {{ isDeleting ? 'Удаляем…' : 'Удалить' }}
      </button>

      <span v-if="deleteError" class="error">{{ deleteError }}</span>
    </footer>
  </article>
</template>

<style scoped>
.back {
  display: inline-block;
  margin-bottom: 1rem;
  font-size: 0.9rem;
  text-decoration: none;
}

.header h1 {
  margin: 0 0 0.5rem;
  font-size: 1.75rem;
  line-height: 1.25;
}

.meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.badge {
  padding: 0.05rem 0.45rem;
  border: 1px solid var(--color-border);
  border-radius: 999px;
}

.excerpt {
  margin: 1.25rem 0 0;
  color: var(--color-text-muted);
  font-size: 1.02rem;
}

.content {
  max-width: 44rem;
  margin-top: 1.5rem;
}

.content p {
  margin: 0 0 1rem;
  white-space: pre-wrap;
}

.actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 2rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--color-border);
}

.actions a {
  text-decoration: none;
}

.button-danger {
  padding: 0.6rem 1rem;
  border: 1px solid var(--color-danger);
  border-radius: var(--radius);
  background: transparent;
  color: var(--color-danger);
  font: inherit;
  cursor: pointer;
}

.button-danger:disabled {
  opacity: 0.6;
  cursor: default;
}

.error {
  color: var(--color-danger);
  font-size: 0.9rem;
}
</style>
