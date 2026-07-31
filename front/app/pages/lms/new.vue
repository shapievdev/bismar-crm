<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { CoursePayload } from '~/types/lms'

definePageMeta({ middleware: 'auth', permission: 'courses.create' })
useHead({ title: 'Новый курс' })

const { createCourse, fetchStatuses } = useLmsApi()
const { can } = useAuth()
const router = useRouter()

const { data: statuses } = await useAsyncData('lms.statuses', () => fetchStatuses())

/**
 * Publishing is a separate permission on the server; offering it to someone
 * who lacks it would only produce a validation error.
 */
const availableStatuses = computed(() =>
  (statuses.value?.data ?? []).filter(
    status => status.value !== 'published' || can('courses.publish'),
  ),
)

const form = reactive<CoursePayload>({
  title: '',
  summary: '',
  description: '',
  status: 'draft',
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSubmitting = ref(false)

async function submit() {
  isSubmitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    const { data } = await createCourse({
      ...form,
      summary: form.summary || null,
      description: form.description || null,
    })

    await router.push(`/lms/${data.slug}`)
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

    <form class="form" novalidate @submit.prevent="submit">
      <FormField id="title" v-model="form.title" label="Название" :errors="errors.title" />

      <div class="field">
        <label for="summary">Краткое описание</label>
        <textarea id="summary" v-model="form.summary" rows="2" maxlength="500" />
        <p v-if="errors.summary?.length" class="field__error">
          {{ errors.summary[0] }}
        </p>
      </div>

      <div class="field">
        <label for="description">Описание</label>
        <textarea id="description" v-model="form.description" rows="8" />
        <p v-if="errors.description?.length" class="field__error">
          {{ errors.description[0] }}
        </p>
      </div>

      <div class="field">
        <label for="status">Статус</label>
        <select id="status" v-model="form.status">
          <option v-for="item in availableStatuses" :key="item.value" :value="item.value">
            {{ item.label }}
          </option>
        </select>
        <p v-if="errors.status?.length" class="field__error">
          {{ errors.status[0] }}
        </p>
      </div>

      <button type="submit" class="button-primary" :disabled="isSubmitting">
        {{ isSubmitting ? 'Создаём…' : 'Создать курс' }}
      </button>
    </form>
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

.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 42rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field label {
  font-size: 0.875rem;
  font-weight: 500;
}

.field textarea,
.field select {
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-surface);
  color: var(--color-text);
  font: inherit;
  resize: vertical;
}

.field__error {
  margin: 0;
  color: var(--color-danger);
  font-size: 0.825rem;
}
</style>
