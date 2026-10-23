<script setup lang="ts">
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'

definePageMeta({ middleware: 'guest' })
useHead({ title: 'Забыли пароль' })

const { requestPasswordReset } = useAuth()

/**
 * Одно поле, и в нём либо номер, либо почта.
 *
 * Маски здесь нет намеренно, хотя у телефона на форме входа она есть: маска
 * телефона, наложенная на набираемый адрес почты, превратила бы его в
 * «+7 (kur) aba-no-v@». Что именно прислали, разбирает сервер.
 */
const login = ref('')

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const answer = ref<string | null>(null)
const isSubmitting = ref(false)

async function handleSubmit() {
  isSubmitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    answer.value = await requestPasswordReset(login.value)
  }
  catch (error) {
    if (error instanceof ApiValidationError) {
      errors.value = error.errors
    }
    else {
      generalError.value = 'Не удалось отправить письмо. Попробуйте позже.'
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
      Забыли пароль
    </h1>

    <!--
      Ответ сервера занимает место формы, а не встаёт над ней: он одинаков на
      любую просьбу, и оставленная рядом форма предлагала бы попробовать снова
      с другим написанием — хотя вторая ссылка подряд всё равно не выдаётся.
    -->
    <p v-if="answer" class="alert alert--success">
      {{ answer }}
    </p>

    <form v-else class="auth-form" novalidate @submit.prevent="handleSubmit">
      <p v-if="generalError" class="auth-alert" role="alert">
        {{ generalError }}
      </p>

      <p class="auth-switch auth-switch--lead">
        Укажите номер телефона, с которым входите, или свою почту. Пришлём на
        почту ссылку, по которой можно поставить новый пароль.
      </p>

      <FormField
        id="login"
        v-model="login"
        label="Телефон или почта"
        autocomplete="username"
        placeholder="+7 (999) 000-99-77 или ivanov@bismar.pro"
        :errors="errors.login"
      />

      <button type="submit" class="button-primary" :disabled="isSubmitting">
        {{ isSubmitting ? 'Отправляем…' : 'Прислать ссылку' }}
      </button>
    </form>

    <p class="auth-switch">
      <NuxtLink to="/login">
        Вернуться к входу
      </NuxtLink>
    </p>
  </div>
</template>

<style scoped>
/* Пояснение стоит над полем, а не под карточкой, — отсюда и отступ сверху. */
.auth-switch--lead {
  margin-top: 0;
}
</style>
