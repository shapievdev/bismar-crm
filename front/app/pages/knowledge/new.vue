<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { ArticlePayload } from '~/types/knowledge'

definePageMeta({ middleware: 'auth', permission: 'knowledge.create' })
useHead({ title: 'Новая статья' })

const { createArticle, fetchCategories, fetchStatuses } = useKnowledgeApi()
const router = useRouter()

const { data: reference } = await useAsyncData('knowledge.reference', async () => {
  const [categories, statuses] = await Promise.all([fetchCategories(), fetchStatuses()])

  return { categories: categories.data, statuses: statuses.data }
})

const form = ref<ArticlePayload>({
  title: '',
  excerpt: '',
  content: '',
  status: 'draft',
  category_id: null,
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSubmitting = ref(false)

async function submit(payload: ArticlePayload) {
  isSubmitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const { data } = await createArticle({ ...payload, excerpt: payload.excerpt || null })
    await router.push(`/knowledge/${data.slug}`)
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
      <h1>Новая статья</h1>
      <NuxtLink to="/knowledge" class="back">
        ← К списку
      </NuxtLink>
    </header>

    <p v-if="generalError" class="auth-alert" role="alert">
      {{ generalError }}
    </p>

    <ArticleForm
      v-model="form"
      :categories="reference?.categories ?? []"
      :statuses="reference?.statuses ?? []"
      :errors="errors"
      :is-submitting="isSubmitting"
      submit-label="Создать"
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
