<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { CoursePayload } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.create' })
useHead({ title: 'Новый курс' })

const { createCourse, fetchStatuses } = useLmsApi()
const router = useRouter()

const { data: statuses } = await useAsyncData('lms.statuses', () => fetchStatuses())

const form = ref<CoursePayload>({
  title: '',
  summary: '',
  description: '',
  status: 'draft',
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSubmitting = ref(false)

async function submit(payload: CoursePayload) {
  isSubmitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const { data } = await createCourse({
      ...payload,
      summary: payload.summary || null,
      description: payload.description || null,
    })

    // Straight into the editor: a new course has no modules yet.
    await router.push(`/lms/${data.slug}/edit`)
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось создать курс.'
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
      <h1>Новый курс</h1>
      <NuxtLink to="/lms" class="back">
        ← К курсам
      </NuxtLink>
    </header>

    <p v-if="generalError" class="auth-alert" role="alert">
      {{ generalError }}
    </p>

    <CourseForm
      v-model="form"
      :statuses="statuses?.data ?? []"
      :errors="errors"
      :is-submitting="isSubmitting"
      submit-label="Создать курс"
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
