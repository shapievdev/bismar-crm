<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { StaffAccountDraft } from '~/types/auth'
import { phoneForApi } from '~/utils/phone'

definePageMeta({ middleware: 'auth', permission: 'users.manage' })
useHead({ title: 'Новый сотрудник' })

const { createUser } = useAdminApi()
const router = useRouter()

const draft = ref<StaffAccountDraft>({
  last_name: '',
  first_name: '',
  middle_name: '',
  email: '',
  phone: '',
  job_title: '',
  password: '',
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSaving = ref(false)

/**
 * Заведённый сотрудник открывается сразу: права ему ещё не отмечены, и делают
 * это на его же экране — там, где видно, кто он и что ему уже доступно.
 */
async function save() {
  isSaving.value = true
  errors.value = {}
  generalError.value = null

  try {
    const { data } = await createUser({
      last_name: draft.value.last_name,
      first_name: draft.value.first_name,
      middle_name: draft.value.middle_name || null,
      email: draft.value.email,
      // Скобки и дефисы — дело показа: на сервер уходит одно число.
      phone: phoneForApi(draft.value.phone),
      job_title: draft.value.job_title || null,
      password: draft.value.password,
    })

    await router.push(`/staff/${data.id}`)
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = 'Не удалось завести сотрудника.'
    }
  }
  finally {
    isSaving.value = false
  }
}
</script>

<template>
  <section>
    <header class="page-header">
      <NuxtLink to="/staff" class="back">
        ← Сотрудники
      </NuxtLink>

      <h1>Новый сотрудник</h1>
      <p class="muted">
        Прав у него пока нет: их отмечают на его карточке, отдельным решением.
      </p>
    </header>

    <p v-if="generalError" class="auth-alert" role="alert">
      {{ generalError }}
    </p>

    <form class="card panel" novalidate @submit.prevent="save">
      <StaffAccountFields v-model="draft" mode="create" :errors="errors" />

      <div class="panel__actions">
        <button type="submit" class="button-primary" :disabled="isSaving">
          {{ isSaving ? 'Заводим…' : 'Завести' }}
        </button>
        <NuxtLink to="/staff" class="button-secondary">
          Отмена
        </NuxtLink>
      </div>
    </form>
  </section>
</template>

<style scoped>
.page-header {
  margin-bottom: 1.5rem;
}

.page-header h1 {
  margin: 0 0 0.25rem;
  font-size: 1.5rem;
}

.back {
  display: inline-block;
  margin-bottom: 0.6rem;
  color: var(--color-text-muted);
  font-size: 0.85rem;
  text-decoration: none;
}

.back:hover {
  color: var(--color-text);
}

.muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.25rem 1.4rem;
}

.panel__actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}
</style>
