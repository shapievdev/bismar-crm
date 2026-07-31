<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { ArticlePayload } from '~/types/knowledge'

definePageMeta({ middleware: 'auth', permission: 'knowledge.update' })

const route = useRoute()
const router = useRouter()
const { fetchArticle, updateArticle, fetchCategories, fetchStatuses } = useKnowledgeApi()

const slug = computed(() => String(route.params.slug))

const { data, error } = await useAsyncData(
  () => `knowledge.edit.${slug.value}`,
  async () => {
    const [article, categories, statuses] = await Promise.all([
      fetchArticle(slug.value),
      fetchCategories(),
      fetchStatuses(),
    ])

    return { article: article.data, categories: categories.data, statuses: statuses.data }
  },
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Статья не найдена', fatal: true })
}

useHead(() => ({ title: `Редактирование — ${data.value?.article.title ?? ''}` }))

const form = ref<ArticlePayload>({
  title: data.value?.article.title ?? '',
  excerpt: data.value?.article.excerpt ?? '',
  content: data.value?.article.content ?? '',
  status: data.value?.article.status ?? 'draft',
  category_id: data.value?.article.category?.id ?? null,
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSubmitting = ref(false)

async function submit(payload: ArticlePayload) {
  isSubmitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const { data: saved } = await updateArticle(slug.value, {
      ...payload,
      excerpt: payload.excerpt || null,
    })

    // Renaming an unpublished article changes its slug, so follow it.
    await router.push(`/knowledge/${saved.slug}`)
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить статью.'
    }
  }
  finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <section>
    <header class="page-header">
      <h1>Редактирование</h1>
      <NuxtLink :to="`/knowledge/${slug}`" class="back">
        ← К статье
      </NuxtLink>
    </header>

    <p v-if="generalError" class="auth-alert" role="alert">
      {{ generalError }}
    </p>

    <ArticleForm
      v-model="form"
      :categories="data?.categories ?? []"
      :statuses="data?.statuses ?? []"
      :errors="errors"
      :is-submitting="isSubmitting"
      submit-label="Сохранить"
      @submit="submit"
    />
  </section>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.25rem;
}

.page-header h1 {
  margin: 0;
  font-size: 1.5rem;
}

.back {
  font-size: 0.9rem;
  text-decoration: none;
}
</style>
