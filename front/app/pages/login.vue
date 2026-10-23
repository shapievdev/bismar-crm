<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import { maskPhone, phoneForApi } from '~/utils/phone'

definePageMeta({ middleware: 'guest' })
useHead({ title: 'Вход' })

const { login } = useAuth()
const route = useRoute()
const router = useRouter()

/** Номер держится в поле разбитым на части — так его и набирают, и читают. */
const form = reactive({
  phone: '',
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
    await login({
      // Скобки и дефисы — дело показа: на сервер уходит одно число. Пустое
      // поле уходит пустым, чтобы о нём сказала проверка, а не тишина.
      phone: phoneForApi(form.phone) ?? '',
      password: form.password,
      remember: form.remember,
    })

    await goAfterLogin()
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

/**
 * Куда вести после входа.
 *
 * Обычно — туда, откуда человека сюда отправили, и это страница Nuxt: `push`
 * открывает её, не перезагружая приложение. Но один адрес приложению не
 * принадлежит — `/oauth/authorize`, экран согласия для коннектора claude.ai:
 * его отдаёт Laravel, и `push` показал бы вместо него «страница не найдена».
 * Такие пути отдаются браузеру.
 *
 * Берётся только путь и только свой — начинающийся с одной косой черты. Иначе
 * «?redirect=https://чужой-сайт» превратил бы форму входа в удобный перенос:
 * человек вошёл у нас, а оказался неизвестно где.
 */
async function goAfterLogin() {
  const { redirect } = route.query
  const target = typeof redirect === 'string' && /^\/(?!\/)/.test(redirect) ? redirect : '/'

  if (target.startsWith('/oauth/')) {
    window.location.assign(target)

    return
  }

  await router.push(target)
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
        id="phone"
        v-model="form.phone"
        label="Телефон"
        type="tel"
        inputmode="tel"
        autocomplete="tel"
        placeholder="+7 (999) 000-99-77"
        :format="maskPhone"
        :errors="errors.phone"
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

    <!--
      Записаться самому нельзя, и «нет аккаунта?» с ссылкой отсюда убрано. Но
      человек, впервые открывший платформу, не должен упереться в форму без
      объяснений: сказать, куда идти за учётной записью, — единственное, чем
      экран входа может ему помочь.
    -->
    <p class="auth-switch">
      Нет учётной записи? Её заводит администратор — обратитесь к нему.
    </p>
  </div>
</template>