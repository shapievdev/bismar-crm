<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'

definePageMeta({ middleware: 'guest' })
useHead({ title: 'Новый пароль' })

const { resetPassword } = useAuth()
const route = useRoute()
const router = useRouter()

/**
 * Номер сотрудника и токен приезжают ссылкой из письма, а не набираются.
 *
 * Неполный адрес — обычное дело: письмо переносят в другую программу, ссылку
 * копируют до конца строки. Об этом говорится сразу, а не после того, как
 * человек придумает пароль и нажмёт кнопку.
 */
const user = Number(route.query.user ?? 0)
const token = String(route.query.token ?? '')
const isLinkComplete = computed(() => Number.isInteger(user) && user > 0 && token !== '')

const form = reactive({
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
    await resetPassword({
      user,
      token,
      password: form.password,
      password_confirmation: form.password_confirmation,
    })

    /*
     * Войти человека отсюда нельзя — сессию по ссылке из письма не открывают,
     * а письма пересылают. Поэтому ведём на форму входа, и ведём с объяснением:
     * «пароль изменён» на пустой форме входа читается как «что-то случилось».
     */
    await router.push({ path: '/login', query: { changed: '1' } })
  }
  catch (error) {
    if (error instanceof ApiValidationError) {
      errors.value = error.errors
    }
    else {
      generalError.value = 'Не удалось сменить пароль. Попробуйте позже.'
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
      Новый пароль
    </h1>

    <template v-if="isLinkComplete">
      <form class="auth-form" novalidate @submit.prevent="handleSubmit">
        <p v-if="generalError" class="auth-alert" role="alert">
          {{ generalError }}
        </p>

        <!--
          Отказ по токену — не ошибка поля: поля этого в форме нет, он приехал
          ссылкой. Поэтому он стоит над формой, где человек его прочтёт.
        -->
        <p v-if="errors.token" class="auth-alert" role="alert">
          {{ errors.token[0] }}
        </p>

        <FormField
          id="password"
          v-model="form.password"
          label="Новый пароль"
          type="password"
          autocomplete="new-password"
          hint="Не короче восьми знаков."
          :errors="errors.password"
        />

        <FormField
          id="password_confirmation"
          v-model="form.password_confirmation"
          label="Ещё раз"
          type="password"
          autocomplete="new-password"
        />

        <button type="submit" class="button-primary" :disabled="isSubmitting">
          {{ isSubmitting ? 'Сохраняем…' : 'Сохранить пароль' }}
        </button>
      </form>

      <p class="auth-switch">
        Войти в платформу после этого нужно по номеру телефона — он остался
        прежним.
      </p>
    </template>

    <template v-else>
      <p class="auth-alert" role="alert">
        Ссылка неполная. Откройте её из письма целиком — или попросите новую.
      </p>

      <p class="auth-switch">
        <NuxtLink to="/password/forgot">
          Прислать новую ссылку
        </NuxtLink>
      </p>
    </template>
  </div>
</template>
