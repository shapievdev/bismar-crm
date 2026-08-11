<script setup lang="ts">
import type { FetchError } from 'ofetch'
import { ApiValidationError, type ValidationErrors } from '~/composables/useAuth'
import type { AiSettings } from '~/types/ai'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Консультант' })

const { isSuperAdmin } = useAuth()
const { fetchSettings, saveSettings, testConnection } = useAiApi()

// Страница целиком принадлежит суперадминистратору: здесь платёжный ключ.
if (!isSuperAdmin.value) {
  throw createError({ statusCode: 403, statusMessage: 'Недостаточно прав', fatal: true })
}

const { data, refresh } = await useAsyncData('ai.settings', () => fetchSettings())

const settings = computed<AiSettings | undefined>(() => data.value?.data)

const form = reactive({
  model: settings.value?.model ?? '',
  embedding_model: settings.value?.embedding_model ?? '',
  base_url: settings.value?.base_url ?? '',
  api_key: '',
  auth_scheme: settings.value?.auth_scheme ?? 'bearer',
  max_tokens: settings.value?.max_tokens ?? null as number | null,
})

const errors = ref<ValidationErrors>({})
const generalError = ref<string | null>(null)
const isSaving = ref(false)
const savedAt = ref<string | null>(null)

const isTesting = ref(false)
const testResult = ref<{ ok: boolean, message: string } | null>(null)

/**
 * Пустое поле — это «по умолчанию», а не ноль: число тут необязательное, а
 * `<input type="number">` работает со строкой.
 */
const maxTokens = computed({
  get: () => (form.max_tokens === null ? '' : String(form.max_tokens)),
  set: (value: string) => {
    form.max_tokens = value.trim() === '' ? null : Number(value)
  },
})

const schemeOptions = computed(() =>
  (settings.value?.schemes ?? []).map(scheme => ({ value: scheme.value, label: scheme.label })),
)

async function save(): Promise<void> {
  isSaving.value = true
  errors.value = {}
  generalError.value = null
  savedAt.value = null
  testResult.value = null

  try {
    await saveSettings({
      model: form.model || null,
      embedding_model: form.embedding_model || null,
      base_url: form.base_url || null,
      // Пустое поле означает «оставить прежний ключ»: форма его не показывает
      // и потому не может прислать обратно.
      api_key: form.api_key || null,
      auth_scheme: form.auth_scheme,
      max_tokens: form.max_tokens,
    })

    form.api_key = ''
    savedAt.value = new Date().toLocaleTimeString('ru-RU')
    await refresh()
  }
  catch (caught) {
    if (caught instanceof ApiValidationError) {
      errors.value = caught.errors
    }
    else {
      generalError.value = (caught as FetchError<{ message?: string }>).data?.message
        ?? 'Не удалось сохранить настройки.'
    }
  }
  finally {
    isSaving.value = false
  }
}

/** Спрашивает модель на два слова — дешевле, чем узнать о поломке от людей. */
async function check(): Promise<void> {
  isTesting.value = true
  testResult.value = null

  try {
    const { message } = await testConnection()

    testResult.value = { ok: true, message }
  }
  catch (caught) {
    testResult.value = {
      ok: false,
      message: (caught as FetchError<{ message?: string }>).data?.message ?? 'Проверка не удалась.',
    }
  }
  finally {
    isTesting.value = false
  }
}
</script>

<template>
  <section>
    <header class="head">
      <div>
        <h1 class="page-title">
          Консультант
        </h1>
        <p class="page-subtitle">
          Модель, адрес и ключ. Пустое поле означает «взять из переменных окружения».
        </p>
      </div>
    </header>

    <p v-if="generalError" class="alert alert--danger" role="alert">
      {{ generalError }}
    </p>

    <form class="card card--raised form" novalidate @submit.prevent="save">
      <FormField
        id="model"
        v-model="form.model"
        label="Модель"
        :errors="errors.model"
        placeholder="gpt-4o-mini"
      />
      <p class="hint">
        Сейчас применяется: <b>{{ settings?.effective.model }}</b>
      </p>

      <FormField
        id="embedding_model"
        v-model="form.embedding_model"
        label="Модель смыслового поиска"
        :errors="errors.embedding_model"
        placeholder="text-embedding-3-small"
      />
      <p class="hint">
        Без неё поиск идёт только по словам и не связывает «как подобрать краску»
        с «Матрицей подбора по помещениям» — общих слов у них нет.
        <template v-if="settings?.effective.embedding_model">
          Сейчас применяется: <b>{{ settings.effective.embedding_model }}</b>.
        </template>
        <template v-else>Сейчас не задана.</template>
        После смены модели выполните <code>php artisan lms:reindex-passages</code>.
      </p>

      <FormField
        id="base_url"
        v-model="form.base_url"
        label="Адрес API"
        :errors="errors.base_url"
        placeholder="https://api.aitunnel.ru"
      />
      <p class="hint">
        Без <code>/v1</code> — приложение дописывает путь само.
        Сейчас применяется: <b>{{ settings?.effective.base_url }}</b>
      </p>

      <div class="field">
        <label for="api_key">Ключ</label>
        <input
          id="api_key"
          v-model="form.api_key"
          type="password"
          autocomplete="off"
          :placeholder="settings?.has_key ? 'Сохранён — введите новый, чтобы заменить' : 'Ключ не задан'"
        >
        <p v-if="errors.api_key?.length" class="field__error">
          {{ errors.api_key[0] }}
        </p>
        <p class="hint">
          <template v-if="settings?.key_hint">
            Сохранён ключ, оканчивающийся на <b>{{ settings.key_hint }}</b>. Показать его целиком нельзя.
          </template>
          <template v-else-if="settings?.has_key">
            Ключ берётся из переменных окружения.
          </template>
          <template v-else>
            Пока не задан — консультант отвечать не сможет.
          </template>
        </p>
      </div>

      <div class="field">
        <label for="auth_scheme">Как передавать ключ</label>
        <UiSelect id="auth_scheme" v-model="form.auth_scheme" :options="schemeOptions" />
        <p class="hint">
          Ошибка здесь выглядит как «неверный ключ»: эндпоинт не находит его там, где ищет.
        </p>
      </div>

      <div class="field">
        <label for="max_tokens">Максимум токенов в ответе</label>
        <input
          id="max_tokens"
          v-model="maxTokens"
          type="number"
          min="256"
          max="8192"
          :placeholder="String(settings?.effective.max_tokens ?? 1024)"
        >
        <p v-if="errors.max_tokens?.length" class="field__error">
          {{ errors.max_tokens[0] }}
        </p>
      </div>

      <footer class="actions">
        <button type="submit" class="button-primary" :disabled="isSaving">
          {{ isSaving ? 'Сохраняем…' : 'Сохранить' }}
        </button>

        <button type="button" class="button-secondary" :disabled="isTesting" @click="check">
          {{ isTesting ? 'Проверяем…' : 'Проверить связь' }}
        </button>

        <span v-if="savedAt" class="faint">Сохранено в {{ savedAt }}</span>
      </footer>

      <p
        v-if="testResult"
        class="alert"
        :class="testResult.ok ? 'alert--success' : 'alert--danger'"
        role="status"
      >
        {{ testResult.message }}
      </p>
    </form>

    <p v-if="settings?.updated_at" class="faint updated">
      Последнее изменение: {{ new Date(settings.updated_at).toLocaleString('ru-RU') }}
      <template v-if="settings.updated_by">— {{ settings.updated_by }}</template>
    </p>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: 1.75rem;
}

.form {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  max-width: 42rem;
  padding: 1.5rem;
}

.form :deep(.field) {
  margin-bottom: 0.35rem;
}

.hint {
  margin: 0 0 1rem;
  font-size: 0.82rem;
  line-height: 1.45;
  color: var(--color-text-faint);
}

.hint code {
  padding: 0.05em 0.3em;
  border-radius: var(--radius-sm);
  background: var(--control-surface-hover);
  font-size: 0.95em;
}

.actions {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-top: 0.75rem;
}

.alert {
  margin-top: 1rem;
}

.updated {
  display: block;
  margin-top: 1rem;
  font-size: 0.82rem;
}
</style>
