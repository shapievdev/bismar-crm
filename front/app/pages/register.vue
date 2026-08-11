<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'

definePageMeta({ middleware: 'guest' })
useHead({ title: 'Регистрация' })

const { register } = useAuth()
const router = useRouter()

const form = reactive({
  last_name: '',
  first_name: '',
  middle_name: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSubmitting = ref(false)

async function handleSubmit() {
  isSubmitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await register(form)
    await router.push('/')
  }
  catch (error) {
    if (error instanceof ApiValidationError) {
      errors.value = error.errors
    }
    else {
      generalError.value = 'Не удалось зарегистрироваться. Попробуйте позже.'
    }
  }
  finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <div class="auth-card">
    <BrandMark :size="52" class="auth-card__logo" />

    <h1 class="auth-card__title">
      Регистрация
    </h1>

    <form class="auth-form" novalidate @submit.prevent="handleSubmit">
      <p v-if="generalError" class="auth-alert" role="alert">
        {{ generalError }}
      </p>

      <FormField
        id="last_name"
        v-model="form.last_name"
        label="Фамилия"
        autocomplete="family-name"
        :errors="errors.last_name"
      />

      <FormField
        id="first_name"
        v-model="form.first_name"
        label="Имя"
        autocomplete="given-name"
        :errors="errors.first_name"
      />

      <FormField
        id="middle_name"
        v-model="form.middle_name"
        label="Отчество — если есть"
        autocomplete="additional-name"
        :errors="errors.middle_name"
      />

      <FormField
        id="email"
        v-model="form.email"
        label="Email"
        type="email"
        autocomplete="email"
        :errors="errors.email"
      />

      <FormField
        id="password"
        v-model="form.password"
        label="Пароль"
        type="password"
        autocomplete="new-password"
        :errors="errors.password"
      />

      <FormField
        id="password_confirmation"
        v-model="form.password_confirmation"
        label="Подтверждение пароля"
        type="password"
        autocomplete="new-password"
        :errors="errors.password_confirmation"
      />

      <button type="submit" class="button-primary" :disabled="isSubmitting">
        {{ isSubmitting ? 'Создаём аккаунт…' : 'Зарегистрироваться' }}
      </button>
    </form>

    <p class="auth-switch">
      Уже есть аккаунт?
      <NuxtLink to="/login">
        Войти
      </NuxtLink>
    </p>
  </div>
</template>