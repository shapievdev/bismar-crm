<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'

definePageMeta({ middleware: 'guest' })
useHead({ title: 'Вход' })

const { login } = useAuth()
const route = useRoute()
const router = useRouter()

const form = reactive({
  email: '',
  password: '',
  remember: false,
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSubmitting = ref(false)

async function handleSubmit() {
  isSubmitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await login(form)

    const { redirect } = route.query
    await router.push(typeof redirect === 'string' ? redirect : '/')
  }
  catch (error) {
    if (error instanceof ApiValidationError) {
      errors.value = error.errors
    }
    else {
      generalError.value = 'Не удалось войти. Попробуйте позже.'
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
      Вход
    </h1>

    <form class="auth-form" novalidate @submit.prevent="handleSubmit">
      <p v-if="generalError" class="auth-alert" role="alert">
        {{ generalError }}
      </p>

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
        autocomplete="current-password"
        :errors="errors.password"
      />

      <label class="auth-checkbox">
        <input v-model="form.remember" type="checkbox">
        Запомнить меня
      </label>

      <button type="submit" class="button-primary" :disabled="isSubmitting">
        {{ isSubmitting ? 'Входим…' : 'Войти' }}
      </button>
    </form>

    <p class="auth-switch">
      Нет аккаунта?
      <NuxtLink to="/register">
        Зарегистрироваться
      </NuxtLink>
    </p>
  </div>
</template>