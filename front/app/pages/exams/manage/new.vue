<script setup lang="ts">
import type { ValidationErrors } from '~/composables/useAuth'
import { ApiValidationError } from '~/composables/useAuth'

definePageMeta({ middleware: 'auth', permission: 'exams.manage' })
useHead({ title: 'Новый экзамен' })

const { createExam } = useExamsApi()
const router = useRouter()

/**
 * Здесь только название: окно, таймер и вопросы — на экране правки. Экзамен
 * заводится черновиком всегда, публиковать пустое нечего.
 */
const title = ref('')
const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSaving = ref(false)

async function save() {
  isSaving.value = true
  errors.value = {}
  generalError.value = null

  try {
    const { data } = await createExam({ title: title.value })

    await router.push(`/exams/manage/${data.slug}`)
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось создать экзамен.'
    }
  }
  finally {
    isSaving.value = false
  }
}
</script>

<template>
  <section>
    <header class="head">
      <h1 class="page-title">
        Новый экзамен
      </h1>
      <p class="page-subtitle">
        Назовите его — остальное заполните в редакторе.
      </p>
    </header>

    <p v-if="generalError" class="alert alert--danger" role="alert">
      {{ generalError }}
    </p>

    <form class="card form" @submit.prevent="save">
      <div class="field">
        <label class="field-label" for="title">Название</label>
        <input id="title" v-model.trim="title" class="input" maxlength="255" required>
        <p v-if="errors.title?.length" class="field-error">
          {{ errors.title[0] }}
        </p>
      </div>

      <div class="form__actions">
        <button type="submit" class="button-primary" :disabled="isSaving">
          {{ isSaving ? 'Создаём…' : 'Создать черновик' }}
        </button>
        <NuxtLink to="/exams/manage" class="button-ghost">
          Отмена
        </NuxtLink>
      </div>
    </form>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.75rem;
}

.form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.4rem 1.5rem;
  max-width: 40rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.form__actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.form__actions a {
  text-decoration: none;
}
</style>
