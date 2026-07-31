<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { CoursePayload } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.update' })

const route = useRoute()
const router = useRouter()
const { fetchCourse, updateCourse, fetchStatuses } = useLmsApi()

const slug = computed(() => String(route.params.slug))

const { data, error, refresh } = await useAsyncData(
  () => `lms.edit.${slug.value}`,
  async () => {
    const [course, statuses] = await Promise.all([fetchCourse(slug.value), fetchStatuses()])

    return { course: course.data, statuses: statuses.data }
  },
)

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Курс не найден', fatal: true })
}

useHead(() => ({ title: `Редактирование — ${data.value?.course.title ?? ''}` }))

const form = ref<CoursePayload>({
  title: data.value?.course.title ?? '',
  summary: data.value?.course.summary ?? '',
  description: data.value?.course.description ?? '',
  status: data.value?.course.status ?? 'draft',
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSubmitting = ref(false)
const savedAt = ref<string | null>(null)

async function submit(payload: CoursePayload) {
  isSubmitting.value = true
  errors.value = {}
  generalError.value = null
  savedAt.value = null

  try {
    const { data: saved } = await updateCourse(slug.value, {
      ...payload,
      summary: payload.summary || null,
      description: payload.description || null,
    })

    // An unpublished course changes slug when retitled, so follow it.
    if (saved.slug !== slug.value) {
      await router.replace(`/lms/${saved.slug}/edit`)
    }

    await refresh()
    savedAt.value = new Date().toLocaleTimeString('ru-RU')
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось сохранить курс.'
    }
  }
  finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <section v-if="data">
    <header class="page-header">
      <h1>Редактирование курса</h1>
      <NuxtLink :to="`/lms/${slug}`" class="back">
        ← К курсу
      </NuxtLink>
    </header>

    <p v-if="generalError" class="auth-alert" role="alert">
      {{ generalError }}
    </p>

    <CourseForm
      v-model="form"
      :statuses="data.statuses"
      :errors="errors"
      :is-submitting="isSubmitting"
      submit-label="Сохранить"
      @submit="submit"
    >
      <template #secondary-actions>
        <span v-if="savedAt" class="muted">Сохранено в {{ savedAt }}</span>
      </template>
    </CourseForm>

    <ModuleTree
      :course-slug="slug"
      :modules="data.course.modules ?? []"
      @changed="refresh"
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

.muted {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}
</style>
